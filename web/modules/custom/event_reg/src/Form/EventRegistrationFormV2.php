<?php

namespace Drupal\event_reg\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public event registration form.
 */
class EventRegistrationFormV2 extends FormBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_reg_registration_form';
  }

  /**
   * {@inheritdoc}
   * 
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $now = \Drupal::time()->getRequestTime();
   

    // STEP B2: Check if registration window is open.
    $event = $this->database->select('event_reg_event', 'e')
      ->fields('e')
      ->condition('registration_start', $now, '<=')
      ->condition('registration_end', $now, '>=')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    // Registration closed.
    if (!$event) {
      $form['message'] = [
        '#markup' => '<p><strong>Registration is currently closed.</strong></p>',
      ];
      return $form;
    }

    // -------------------------------
    // STEP B3: Dynamic AJAX dropdowns
    // -------------------------------

    $selected_category = $form_state->getValue('category');
    $selected_date = $form_state->getValue('event_date');



    $form['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
    ];

    $form['college'] = [
      '#type' => 'textfield',
      '#title' => $this->t('College Name'),
      '#required' => TRUE,
    ];

    $form['department'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Department'),
      '#required' => TRUE,
    ];

    // Category dropdown.
    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => ['' => $this->t('- Select -')] + $this->getCategories(),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateEventDates',
        'wrapper' => 'event-date-wrapper',
      ],
    ];

    // Event Date (AJAX wrapper).
    $form['event_date_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'event-date-wrapper'],
    ];

    $form['event_date_wrapper']['event_date'] = [
  '#type' => 'select',
  '#title' => $this->t('Event Date'),
  '#options' => $selected_category
    ? ['' => $this->t('- Select -')] + $this->getEventDates($selected_category)
    : ['' => $this->t('- Select -')],
  '#required' => TRUE,
  '#ajax' => [
    'callback' => '::updateEventNames',
    'wrapper' => 'event-name-wrapper',
  ],
];


    // Event Name (AJAX wrapper).
    $form['event_name_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'event-name-wrapper'],
    ];

    $form['event_name_wrapper']['event_name'] = [
  '#type' => 'select',
  '#title' => $this->t('Event Name'),
  '#options' => ($selected_category && $selected_date)
    ? ['' => $this->t('- Select -')] + $this->getEventNames($selected_category, (int) $selected_date)
    : ['' => $this->t('- Select -')],
  '#required' => TRUE,
];




    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register'),
    ];

    return $form;
  }

  /**
   * Get distinct categories.
   */
  protected function getCategories(): array {
    $result = $this->database->select('event_reg_event', 'e')
      ->fields('e', ['category'])
      ->distinct()
      ->execute()
      ->fetchCol();

    return array_combine($result, $result);
  }

  /**
   * Get event dates by category.
   */
  protected function getEventDates(string $category): array {
  $now = \Drupal::time()->getRequestTime();

  $query = $this->database->select('event_reg_event', 'e');
  $query->fields('e', ['event_date']);
  $query->condition('category', $category);
  $query->condition('registration_start', $now, '<=');
  $query->condition('registration_end', $now, '>=');
  $query->distinct();

  $result = $query->execute()->fetchCol();

  $dates = [];
  foreach ($result as $timestamp) {
    $dates[$timestamp] = date('d M Y', $timestamp);
  }

  return $dates;
}


  /**
   * Get event names by category and date.
   */
  protected function getEventNames(string $category, int $event_date): array {
  $query = $this->database->select('event_reg_event', 'e');
  $query->fields('e', ['id', 'event_name']);
  $query->condition('category', $category);
  $query->condition('event_date', $event_date);

  $result = $query->execute()->fetchAllKeyed();

  \Drupal::logger('event_reg')->notice(
    'Loaded event names | Category: @cat | Date: @date | Count: @count',
    [
      '@cat' => $category,
      '@date' => $event_date,
      '@count' => count($result),
    ]
  );

  return $result;
}

    // ----------------------------------------------------------
    // AJAX callbacks for dynamic dropdowns

// ----------------------------------------------------------
  public function updateEventDates(array &$form, FormStateInterface $form_state) {
  $form_state->setRebuild();
  return $form['event_date_wrapper'];
}

public function updateEventNames(array &$form, FormStateInterface $form_state) {
  $form_state->setRebuild();
  return $form['event_name_wrapper'];
}


  /**
   * {@inheritdoc}
   */
 public function submitForm(array &$form, FormStateInterface $form_state): void {
  $this->messenger()->addStatus(
    $this->t('Form submitted successfully (data saving will be added next).')
  );
}


  /**
 * {@inheritdoc}
 */
public function validateForm(array &$form, FormStateInterface $form_state): void {

  // 1. Disallow special characters in text fields.
  $text_fields = [
    'full_name' => 'Full Name',
    'college' => 'College Name',
    'department' => 'Department',
  ];

  foreach ($text_fields as $field => $label) {
    $value = $form_state->getValue($field);
    if (!preg_match('/^[a-zA-Z\s]+$/', $value)) {
      $form_state->setErrorByName(
        $field,
        $this->t('@field should contain only letters and spaces.', ['@field' => $label])
      );
    }
  }

  // 2. Validate email format.
  $email = $form_state->getValue('email');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $form_state->setErrorByName(
    'email',
    $this->t('Please enter a valid email address (example: name@example.com).')
  );
}


  // 3. Prevent duplicate registration (Email + Event ID).
  $event_id = $form_state->getValue('event_name');

  if ($email && $event_id) {
    $exists = $this->database->select('event_reg_registration', 'r')
      ->condition('email', $email)
      ->condition('event_id', $event_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($exists) {
      $form_state->setErrorByName(
        'email',
        $this->t('You have already registered for this event.')
      );
    }
  }
}



}
