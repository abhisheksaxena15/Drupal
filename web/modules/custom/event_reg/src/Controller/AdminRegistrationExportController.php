<?php

namespace Drupal\event_reg\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AdminRegistrationExportController extends ControllerBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  public function export() {

    $header = [
      'ID',
      'Full Name',
      'Email',
      'College',
      'Department',
      'Event Name',
      'Event Date',
      'Registered On',
    ];

    $rows = [];
    $rows[] = $header;

    $query = $this->database->select('event_reg_registration', 'r')
      ->fields('r')
      ->orderBy('created', 'DESC');

    $result = $query->execute();

    foreach ($result as $row) {

      $event = $this->database->select('event_reg_event', 'e')
        ->fields('e', ['event_name', 'event_date'])
        ->condition('id', $row->event_id)
        ->execute()
        ->fetchObject();

      $rows[] = [
        $row->id,
        $row->full_name,
        $row->email,
        $row->college_name,
        $row->department,
        $event->event_name ?? '-',
        isset($event->event_date) ? date('d M Y', $event->event_date) : '-',
        date('d M Y H:i', $row->created),
      ];
    }

    // Build CSV
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="event_registrations.csv"'
    );

    return $response;
  }
}
