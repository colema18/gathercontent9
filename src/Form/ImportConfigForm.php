<?php

namespace Drupal\gathercontent\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ImportConfigForm.
 *
 * @package Drupal\gathercontent\Form
 */
class ImportConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'gathercontent.import',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gathercontent_import_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gathercontent.import');

    $form['node_default_status'] = [
      '#type' => 'radios',
      '#required' => TRUE,
      '#title' => $this->t('Node default status'),
      '#default_value' => $config->get('node_default_status'),
      '#options' => [
        0 => $this->t('Unpublished'),
        1 => $this->t('Published'),
      ],
    ];

    $form['node_create_new_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create new revision'),
      '#default_value' => $config->get('node_create_new_revision'),
      '#description' => $this->t('If this option is set, then the updated entity will be created as a new revision.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('gathercontent.import')
      ->set('node_default_status', $form_state->getValue('node_default_status'))
      ->set('node_update_method', $form_state->getValue('node_update_method'))
      ->set('node_create_new_revision', $form_state->getValue('node_create_new_revision'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
