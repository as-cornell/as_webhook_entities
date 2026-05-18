<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads remote images and creates or reuses Drupal file and media entities.
 *
 * If the destination file already exists locally it is reused without
 * re-downloading. If a media entity of bundle 'image' already references
 * the file it is returned as-is.
 */
class WebhookImageImporter {

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
    // original file rather than a styled derivative.
    $url = html_entity_decode(strtok($url, '?'));
    $url = preg_replace('#/sites/default/files/styles/[^/]+/public/#', '/sites/default/files/', $url);

    $filename = basename(parse_url($url, PHP_URL_PATH));
    if (empty($filename)) {
      return NULL;
    }

    $filename = $this->sanitizeFilename($filename);
    if (empty($filename)) {
      return NULL;
    }

    $destination = 'public://webhook-images/' . $filename;

    // Reuse the existing managed file if it already exists anywhere in public://.
    $fids = $this->entityTypeManager->getStorage('file')
      ->getQuery()
      ->condition('uri', 'public://%/' . $filename, 'LIKE')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    $files = $fids ? $this->entityTypeManager->getStorage('file')->loadMultiple($fids) : [];

    if (!empty($files)) {
      $file = reset($files);
    }
    else {
      try {
        $dir = dirname($destination);
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $response = $this->httpClient->request('GET', $url, ['http_errors' => FALSE]);
        if ($response->getStatusCode() !== 200) {
          $this->logger->warning('Webhook image import failed for @url: HTTP @code', [
            '@url'  => $url,
            '@code' => $response->getStatusCode(),
          ]);
          return NULL;
        }
        $file = $this->fileRepository->writeData(
          $response->getBody()->getContents(),
          $destination,
          FileSystemInterface::EXISTS_REPLACE
        );
        if ($file) {
          $this->applyCrops($file);
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Webhook image import failed for @url: @error', [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    // Reuse an existing media entity that already references this file,
    // or create a new one.
    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->getQuery()
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', $file->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($existing)) {
      $media = $media_storage->load((int) reset($existing));
    }
    else {
      $media = $media_storage->create([
        'bundle' => 'image',
        'name' => $filename,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $alt,
        ],
      ]);
      $media->save();
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
  protected function applyCrops(\Drupal\file\FileInterface $file): void {
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
