<?php

namespace Drupal\event_reg\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AdminRegistrationListForm extends FormBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function getFormId(): string {
    return 'event_reg_admin_registration_list';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {

    $selected_date = $form_state->getValue('event_date');
    $selected_event = $form_state->getValue('event_id');

    // -------- FILTERS --------
    $form['filters'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Filters'),
    ];

    $form['filters']['event_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Event Date'),
      '#options' => ['' => '- All -'] + $this->getEventDates(),
      '#ajax' => [
        'callback' => '::reloadTable',
        'wrapper' => 'registration-table',
      ],
    ];

    $form['filters']['event_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Event Name'),
      '#options' => ['' => '- All -'] + $this->getEvents($selected_date),
      '#ajax' => [
        'callback' => '::reloadTable',
        'wrapper' => 'registration-table',
      ],
    ];

    $form['export'] = [
  '#type' => 'link',
  '#title' => $this->t('Export CSV'),
  '#url' => \Drupal\Core\Url::fromRoute('event_reg.registration_export'),
  '#attributes' => [
    'class' => ['button', 'button--primary'],
    'target' => '_blank',
  ],
];
 
    // -------- TABLE --------
    $header = [
      'name' => $this->t('Name'),
      'email' => $this->t('Email'),
      'event' => $this->t('Event'),
      'college' => $this->t('College'),
      'department' => $this->t('Department'),
      'date' => $this->t('Submitted'),
    ];

    $query = $this->database->select('event_reg_registration', 'r')
      ->fields('r')
      ->orderBy('created', 'DESC');

    if ($selected_event) {
      $query->condition('event_id', $selected_event);
    }

    if ($selected_date) {
      $query->join('event_reg_event', 'e', 'e.id = r.event_id');
      $query->condition('e.event_date', $selected_date);
    }

    $rows = [];
    foreach ($query->execute() as $row) {
      $event = $this->database->select('event_reg_event', 'e')
        ->fields('e', ['event_name'])
        ->condition('id', $row->event_id)
        ->execute()
        ->fetchField();

      $rows[] = [
        $row->full_name,
        $row->email,
        $event,
        $row->college_name,
        $row->department,
        date('d M Y H:i', $row->created),
      ];
    }

    $form['table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'registration-table'],
    ];

    $form['table_wrapper']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No registrations found'),
    ];

    // -------- COUNT --------
    $form['table_wrapper']['count'] = [
      '#markup' => '<p><strong>Total registrations:</strong> ' . count($rows) . '</p>',
    ];

    return $form;
  }

  public function reloadTable(array &$form, FormStateInterface $form_state) {
    return $form['table_wrapper'];
  }

  protected function getEventDates(): array {
    $dates = $this->database->select('event_reg_event', 'e')
      ->fields('e', ['event_date'])
      ->distinct()
      ->execute()
      ->fetchCol();

    $out = [];
    foreach ($dates as $d) {
      $out[$d] = date('d M Y', $d);
    }
    return $out;
  }

  protected function getEvents($date = NULL): array {
    $query = $this->database->select('event_reg_event', 'e')
      ->fields('e', ['id', 'event_name']);

    if ($date) {
      $query->condition('event_date', $date);
    }

    return $query->execute()->fetchAllKeyed();
  }
  /**
 * {@inheritdoc}
 */
public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state): void {
  // This form mainly uses AJAX filters.
  // No default submit action required.
}

}
