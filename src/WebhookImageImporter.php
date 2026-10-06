<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\FileInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads remote images and creates or reuses Drupal file and media entities.
 *
 * A file is reused only when it was stored earlier for the same source URL,
 * or when its bytes are identical to the download; never on a matching name.
 * If a media entity of bundle 'image' already references the file it is
 * returned as-is.
 */
class WebhookImageImporter {

  /**
   * View mode for the <drupal-media> tags written into imported body HTML.
   *
   * <drupal-media> is handled by core's media_embed filter, which reads the
   * bare view mode name from data-view-mode -- not entity_embed's
   * data-entity-embed-display / "view_mode:media.X" plugin ID form. Earlier
   * releases emitted the latter, so the attribute was ignored and every embed
   * silently fell back to the filter's default_view_mode.
   *
   * Hardcoded rather than read from the text format because it must be one of
   * the filter's allowed_view_modes, and reading config here would add a
   * dependency without changing the result. Keep this in step with
   * filter.format.full_html: media_embed currently allows landscape, pano,
   * portrait and thumbnail, and defaults to landscape -- so this value also
   * preserves how existing content already renders.
   *
   * @see \Drupal\media\Plugin\Filter\MediaEmbed
   */
  protected const EMBED_VIEW_MODE = 'landscape';

  /**
   * Most same-sized files compared byte for byte in one lookup.
   *
   * Each comparison hashes a file on disk, so this bounds the cost. Past the
   * limit the image is simply stored as new, which is the safe direction to
   * fail.
   */
  protected const MAX_CONTENT_CANDIDATES = 50;

  /**
   * Entity ids this importer created, until a caller takes them.
   *
   * Lets a caller that can be undone, such as a migration rollback, remove
   * exactly what it caused and never media or files that already existed.
   *
   * @var array{media: int[], file: int[]}
   */
  protected array $created = ['media' => [], 'file' => []];

  /**
   * Constructs a WebhookImageImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param object $fileRepository
   *   The file repository service (Drupal\file\FileRepository).
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ClientInterface $httpClient,
    protected object $fileRepository,
    protected LoggerInterface $logger,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Imports a remote image as a managed file and image media entity.
   *
   * @param string $url
   *   The full URL of the remote image.
   * @param string $alt
   *   Alt text to store on the media entity's image field.
   * @param array $domains
   *   Optional list of domain_access target IDs to apply to the media entity.
   *   Any domains not already present are appended; existing tags are preserved.
   *
   * @return int|null
   *   The media entity ID, or NULL on failure.
   */
  public function importImage(string $url, string $alt, array $domains = []): ?int {
    // Strip query strings and HTML entities, then always resolve to the
    // original file rather than a styled derivative (any site directory).
    $url = html_entity_decode(strtok($url, '?'));
    $url = preg_replace('#/sites/([^/]+)/files/styles/[^/]+/public/#', '/sites/$1/files/', $url);
    // as.cornell.edu serves files/first/ as a legacy alias for sites/default/files/.
    $url = preg_replace('#/files/first/styles/[^/]+/public/#', '/files/first/', $url);

    $filename = urldecode(basename(parse_url($url, PHP_URL_PATH)));
    if (empty($filename)) {
      return NULL;
    }

    $filename = $this->sanitizeFilename($filename);
    if (empty($filename)) {
      return NULL;
    }

    // Key the stored file on the source URL rather than the basename. Two people
    // can both legitimately send hamilton.jpg, and they are not the same
    // photograph. Including a digest of the URL gives each distinct source its
    // own file while still letting a repeat of the same URL reuse one.
    $key = substr(hash('sha256', $url), 0, 12);
    $destination = 'public://webhook-images/' . $key . '-' . $filename;

    // Reuse never rests on a filename alone. Matching any basename anywhere in
    // public:// is how a person named Hamilton was given the illustration from
    // an article about the musical, because both files were called
    // hamilton.jpg. So there are exactly two ways to reuse a file:
    //
    // 1. One this importer made earlier for the same source URL. The URL
    //    digest prefixes the stored filename, so this needs no download.
    // 2. A file whose bytes are identical to the download, whatever it is
    //    called, so a different photograph can never be adopted.
    //
    // Writing to a URL-keyed destination also means EXISTS_REPLACE below can
    // no longer overwrite a different person's photograph.
    $file = $this->findKeyedFile($key, $filename);
    // Files this importer wrote get its default crops. A file adopted by
    // content match belongs to earlier content and is left exactly as it is,
    // because adding a crop where there was none changes how it renders there.
    $owned = (bool) $file;

    if (!$file) {
      try {
        $response = $this->httpClient->request('GET', $url, ['http_errors' => FALSE]);
        if ($response->getStatusCode() !== 200) {
          $this->logger->notice('Webhook image import failed for @url: HTTP @code', [
            '@url'  => $url,
            '@code' => $response->getStatusCode(),
          ]);
          return NULL;
        }
        $data = $response->getBody()->getContents();

        $file = $this->findIdenticalFile($data);
        if (!$file) {
          $dir = dirname($destination);
          $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
          $file = $this->fileRepository->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
          if ($file) {
            $this->created['file'][] = (int) $file->id();
            $owned = TRUE;
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->notice('Webhook image import failed for @url: @error', [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    if (!$file) {
      return NULL;
    }

    // Reuse an existing media entity that already references this file, the
    // oldest when there are several, or create a new one.
    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->getQuery()
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', $file->id())
      ->sort('mid')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($existing)) {
      $media = $media_storage->load((int) reset($existing));
    }
    else {
      // Name the media after its subject rather than its filename. Plenty of
      // sources legitimately send headshot.jpg, and a media library full of
      // identically named items is how an editor ends up attaching the wrong
      // face to a person. The alt text carries the name; fall back to the
      // filename when it is empty.
      $name = trim($alt) !== '' ? mb_substr(trim($alt), 0, 200) : $filename;
      $media = $media_storage->create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $alt,
        ],
      ]);
      $media->save();
      $this->created['media'][] = (int) $media->id();
    }

    // Crops are keyed on the file URI, so they must follow the media save:
    // filefield_paths moves the file to public://YYYY-MM when the media is
    // first saved, and a crop made before that stays on the old webhook-images
    // URI, matching nothing.
    if ($owned) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $file_storage->resetCache([$file->id()]);
      $moved = $file_storage->load($file->id());
      if ($moved) {
        $this->applyCrops($moved);
      }
    }

    // Apply any domain_access values not already present on the media entity.
    if (!empty($domains) && $media) {
      $existing_domains = array_column($media->get('domain_access')->getValue(), 'target_id');
      $to_add = array_diff($domains, $existing_domains);
      if (!empty($to_add)) {
        foreach ($to_add as $domain) {
          $media->get('domain_access')->appendItem(['target_id' => $domain]);
        }
        $media->save();
      }
    }

    return $media ? (int) $media->id() : NULL;
  }

  /**
   * Converts inline remote images in body HTML to local drupal-media embeds.
   *
   * The artsci-as webhook sender uses body->processed, which renders
   * drupal-media embeds to <figure><img src="https://artsci-as..."> HTML
   * before transmission. This method:
   *   1. Finds <figure> blocks that contain absolute-URL <img> tags and
   *      replaces the entire figure with a <drupal-media> embed.
   *   2. Finds any remaining standalone absolute-URL <img> tags and replaces
   *      them with <drupal-media> embeds.
   *
   * Using drupal-media embeds (rather than rewriting src attributes) ensures
   * images are tracked as managed media entities by entity_usage, enabling
   * safe pruning of unused files later.
   *
   * @param string $html
   *   The rendered body HTML from the webhook payload.
   * @param array $domains
   *   Domain_access target IDs to tag any newly created media entities with.
   *
   * @return string
   *   The HTML with remote inline images replaced by local drupal-media embeds.
   *   Any image whose download fails is left untouched.
   */
  public function processBodyHtml(string $html, array $domains = []): string {
    if (empty($html)) {
      return $html;
    }

    // Replace <figure> blocks containing absolute <img> tags. The artsci-as
    // renderer wraps drupal-media image embeds in <figure> elements; replacing
    // the whole figure avoids leaving an orphaned wrapper around the new embed.
    $html = preg_replace_callback(
      '/<figure\b[^>]*>.*?<\/figure>/is',
      function (array $m) use ($domains): string {
        $figure = $m[0];
        if (!preg_match('/<img\b[^>]*\bsrc=["\']?(https?:\/\/[^"\'>\s]+)/i', $figure, $img_m)) {
          return $figure;
        }
        $src = $img_m[1];
        preg_match('/\balt=["\']([^"\']*)["\']?/i', $figure, $alt_m);

        $mid = $this->importImage($src, $alt_m[1] ?? '', $domains);
        if (!$mid) {
          return '';
        }
        $media = $this->entityTypeManager->getStorage('media')->load($mid);
        if (!$media) {
          return $figure;
        }

        $align = '';
        if (preg_match('/\balign-(left|right|center)\b/i', $figure, $align_m)) {
          $align = ' data-align="' . strtolower($align_m[1]) . '"';
        }

        return '<drupal-media data-entity-type="media" data-entity-uuid="'
          . $media->uuid() . '"' . $align
          . ' data-view-mode="' . self::EMBED_VIEW_MODE . '">'
          . '</drupal-media>';
      },
      $html
    );

    // Replace any remaining standalone absolute <img> tags (not inside figures).
    return preg_replace_callback(
      '/<img\b[^>]*\bsrc=["\']?(https?:\/\/[^"\'>\s]+)["\']?[^>]*>/i',
      function (array $m) use ($domains): string {
        $tag = $m[0];
        $src = $m[1];
        preg_match('/\balt=["\']([^"\']*)["\']?/i', $tag, $alt_m);

        $mid = $this->importImage($src, $alt_m[1] ?? '', $domains);
        if (!$mid) {
          return '';
        }
        $media = $this->entityTypeManager->getStorage('media')->load($mid);
        if (!$media) {
          return $tag;
        }

        return '<drupal-media data-entity-type="media" data-entity-uuid="'
          . $media->uuid() . '"'
          . ' data-view-mode="' . self::EMBED_VIEW_MODE . '">'
          . '</drupal-media>';
      },
      $html
    );
  }

  /**
   * Returns and clears the ids of media and files created since the last call.
   *
   * @return array{media: int[], file: int[]}
   *   Ids created by this importer, never ids it merely reused.
   */
  public function takeCreated(): array {
    $created = $this->created;
    $this->created = ['media' => [], 'file' => []];
    return $created;
  }

  /**
   * Finds a file this importer stored earlier for the same source URL.
   *
   * The filefield_paths module moves every file out of webhook-images into
   * public://YYYY-MM when its media is saved, so the original destination URI
   * never matches anything. It also cleans the name on the way, with
   * pathauto's rules: stop words such as "for" and some punctuation are
   * dropped, so lsp-students-for-admissions-pano.jpg is stored as
   * <digest>-lsp-students-admissions-pano.jpg. The digest prefix survives
   * unchanged, and it is derived from the source URL, so it is the key.
   *
   * @param string $key
   *   The 12-character URL digest.
   * @param string $filename
   *   The sanitized source filename, for its extension.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, preferring one that already backs a media entity.
   */
  protected function findKeyedFile(string $key, string $filename): ?FileInterface {
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $fids = $this->entityTypeManager->getStorage('file')
      ->getQuery()
      ->condition('filename', $key . '-%', 'LIKE')
      ->sort('fid')
      ->accessCheck(FALSE)
      ->execute();
    $files = array_filter(
      $fids ? $this->entityTypeManager->getStorage('file')->loadMultiple($fids) : [],
      fn(FileInterface $file) => strcasecmp(pathinfo($file->getFilename(), PATHINFO_EXTENSION), $extension) === 0
        && file_exists($file->getFileUri())
    );
    return $this->preferMediaBacked($files);
  }

  /**
   * Finds an existing file whose bytes are identical to a download.
   *
   * Covers images stored before files were keyed on their URL, such as the
   * June 2026 article import, and the same image reached by two URLs.
   * Candidates are chosen by byte size, not by name: stored names have been
   * rewritten by filefield_paths, and a name match is how a different picture
   * was once adopted. Identity is decided by SHA-256 of the content alone.
   *
   * @param string $data
   *   The downloaded bytes.
   *
   * @return \Drupal\file\FileInterface|null
   *   The matching file, preferring one that already backs a media entity.
   */
  protected function findIdenticalFile(string $data): ?FileInterface {
    $storage = $this->entityTypeManager->getStorage('file');
    $fids = $storage->getQuery()
      ->condition('filesize', strlen($data))
      ->sort('fid')
      ->range(0, static::MAX_CONTENT_CANDIDATES)
      ->accessCheck(FALSE)
      ->execute();
    if (!$fids) {
      return NULL;
    }

    $hash = hash('sha256', $data);
    $matches = array_filter(
      $storage->loadMultiple($fids),
      function (FileInterface $file) use ($hash): bool {
        $uri = $file->getFileUri();
        return file_exists($uri) && hash_file('sha256', $uri) === $hash;
      }
    );
    return $this->preferMediaBacked($matches);
  }

  /**
   * Picks one file from equivalent candidates.
   *
   * Prefers the oldest file already referenced by an image media entity, so
   * reuse lands on the existing media rather than minting a new one around a
   * duplicate file; otherwise the oldest file.
   *
   * @param \Drupal\file\FileInterface[] $files
   *   Equivalent files keyed by id.
   *
   * @return \Drupal\file\FileInterface|null
   *   The chosen file.
   */
  protected function preferMediaBacked(array $files): ?FileInterface {
    if (!$files) {
      return NULL;
    }
    $mids = $this->entityTypeManager->getStorage('media')
      ->getQuery()
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', array_keys($files), 'IN')
      ->sort('mid')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if ($mids) {
      $media = $this->entityTypeManager->getStorage('media')->load((int) reset($mids));
      $fid = $media ? (int) $media->get('field_media_image')->target_id : 0;
      if (isset($files[$fid])) {
        return $files[$fid];
      }
    }
    ksort($files);
    return reset($files);
  }

  /**
   * Sanitizes a filename to lowercase with hyphens and no special characters.
   *
   * @param string $filename
   *   The raw filename, including extension.
   *
   * @return string
   *   The sanitized filename, or an empty string if nothing usable remains.
   */
  protected function sanitizeFilename(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $name = pathinfo($filename, PATHINFO_FILENAME);

    $name = strtolower($name);
    $name = preg_replace('/\s+/', '-', $name);
    $name = preg_replace('/[^a-z0-9\-_]/', '', $name);
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, '-');

    if (empty($name)) {
      return '';
    }

    $valid_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'tiff', 'tif'];
    if ($ext && !in_array($ext, $valid_exts, TRUE)) {
      $this->logger->warning('Webhook image import skipped: unrecognized extension ".@ext" in "@filename"', [
        '@ext'      => $ext,
        '@filename' => $filename,
      ]);
      return '';
    }

    return $ext ? $name . '.' . $ext : $name;
  }

  /**
   * Creates centered Image Widget Crop entities for all four crop types.
   *
   * Skips any crop type that already has a record for this file URI so the
   * method is safe to call on reused files (idempotent).
   *
   * @param \Drupal\file\FileInterface $file
   *   The managed file entity to crop.
   */
  protected function applyCrops(FileInterface $file): void {
    $uri      = $file->getFileUri();
    $realpath = $this->fileSystem->realpath($uri);
    if (empty($realpath) || !file_exists($realpath)) {
      return;
    }

    $size = @getimagesize($realpath);
    if (empty($size) || $size[0] === 0 || $size[1] === 0) {
      return;
    }

    [$img_w, $img_h] = $size;

    // Aspect ratios as [width_ratio, height_ratio].
    $crop_types = [
      'portrait'  => [4, 5],
      'landscape' => [6, 4],
      'square'    => [1, 1],
      'pano'      => [16, 9],
    ];

    $is_portrait_source = $img_h > $img_w;

    foreach ($crop_types as $type_id => [$aw, $ah]) {
      if (Crop::findCrop($uri, $type_id)) {
        continue;
      }
      $scale  = min($img_w / $aw, $img_h / $ah);
      $crop_w = (int) round($aw * $scale);
      $crop_h = (int) round($ah * $scale);

      // For wide crops on portrait source images, shift the crop center upward
      // (to ~25% from top) so heads stay in frame. Clamped to crop_h/2 minimum
      // so the crop rectangle never extends above y=0.
      if ($is_portrait_source && in_array($type_id, ['landscape', 'pano'])) {
        $y = max((int) round($crop_h / 2), (int) round($img_h * 0.25));
      }
      else {
        $y = (int) round($img_h / 2);
      }

      Crop::create([
        'type'   => $type_id,
        'uri'    => $uri,
        'x'      => (int) round($img_w / 2),
        'y'      => $y,
        'width'  => $crop_w,
        'height' => $crop_h,
      ])->save();
    }
  }

}
