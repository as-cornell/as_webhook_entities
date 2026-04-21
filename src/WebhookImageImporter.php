<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
   *
   * @return int|null
   *   The media entity ID, or NULL on failure.
   */
  public function importImage(string $url, string $alt): ?int {
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
        $response = $this->httpClient->get($url);
        $file = $this->fileRepository->writeData(
          $response->getBody()->getContents(),
          $destination,
          FileSystemInterface::EXISTS_REPLACE
        );
      }
      catch (\Exception $e) {
        $this->logger->warning('Webhook image import failed for @url: @error', [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    // Reuse an existing media entity that already references this file.
    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->getQuery()
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', $file->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($existing)) {
      return (int) reset($existing);
    }

    $media = $media_storage->create([
      'bundle' => 'image',
      'name' => $filename,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
      ],
    ]);
    $media->save();

    return (int) $media->id();
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

}
