<?php

namespace Drupal\twig_verbiage_extractor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to handle CSV download.
 */
class DownloadController extends ControllerBase {

  /**
   * Serve the generated CSV file as a download.
   */
  public function download() {
    $csv_uri = \Drupal::state()->get('tve_csv_path', '');

    if (empty($csv_uri)) {
      throw new NotFoundHttpException('No CSV file has been generated yet. Please run the export first.');
    }

    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $real_path   = $file_system->realpath($csv_uri);

    if (!$real_path || !file_exists($real_path)) {
      throw new NotFoundHttpException('CSV file not found. Please run the export again.');
    }

    $filename = 'twig_verbiage_' . date('Ymd_His') . '.csv';

    $response = new BinaryFileResponse($real_path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename
    );
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');

    return $response;
  }

}
