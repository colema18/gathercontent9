<?php

namespace Drupal\gathercontent_ui\Form;

use Cheppers\GatherContent\GatherContentClientInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\gathercontent\MigrationDefinitionCreator;
use Drupal\gathercontent_ui\Form\MappingEditSteps\MappingStepService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MappingEditForm.
 *
 * @package Drupal\gathercontent\Form
 */
class MappingEditForm extends MappingEditFormBase {

  use StringTranslationTrait;

  /**
   * GatherContent client.
   *
   * @var \Drupal\gathercontent\DrupalGatherContentClient
   */
  protected $client;

  /**
   * Step object.
   *
   * @var \Drupal\gathercontent_ui\Form\MappingEditSteps\MappingSteps
   */
  protected $mappingStep;

  /**
   * Mapping step service object.
   *
   * @var \Drupal\gathercontent_ui\Form\MappingEditSteps\MappingStepService
   */
  protected $mappingService;

  /**
   * Migration definition creator.
   *
   * @var \Drupal\gathercontent\MigrationDefinitionCreator
   */
  protected $migrationDefinitionCreator;

  /**
   * MappingEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   EntityFieldManagerInterface.
   * @param \Cheppers\GatherContent\GatherContentClientInterface $client
   *   GatherContent client.
   * @param \Drupal\gathercontent_ui\Form\MappingEditSteps\MappingStepService $mapping_service
   *   MappingStepService.
   * @param \Drupal\gathercontent\MigrationDefinitionCreator $migrationDefinitionCreator
   *   MigrationDefinitionCreator.
   */
  public function __construct(
    EntityFieldManagerInterface $entityFieldManager,
    GatherContentClientInterface $client,
    MappingStepService $mapping_service,
    MigrationDefinitionCreator $migrationDefinitionCreator
  ) {
    parent::__construct($entityFieldManager);

    $this->client = $client;
    $this->mappingService = $mapping_service;
    $this->migrationDefinitionCreator = $migrationDefinitionCreator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('gathercontent.client'),
      $container->get('gathercontent_ui.mapping_service'),
      $container->get('gathercontent.migration_creator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    if (empty($this->step)) {
      $this->step = 'field_mapping';
    }

    $form['#attached']['library'][] = 'gathercontent_ui/theme';
    $form['#attached']['library'][] = 'gathercontent_ui/entity_references';

    /** @var \Drupal\gathercontent\Entity\MappingInterface $mapping */
    $mapping = $this->entity;
    $this->new = !$mapping->hasMapping();

    $template = $this->client->templateGet($mapping->getGathercontentTemplateId());

    if ($this->step === 'field_mapping') {
      if (!$this->new) {
        $this->mappingStep = $this->mappingService->getEditStep($mapping, $template);
      }
      else {
        $this->mappingStep = $this->mappingService->getNewStep($mapping, $template);
      }
    }
    elseif ($this->step === 'er_mapping') {
      // Unset previous form.
      foreach ($form as $k => $item) {
        if (!in_array($k, ['#attributes', '#cache'])) {
          unset($form[$k]);
        }
      }

      $this->mappingStep = $this->mappingService->getEntityReferenceStep($mapping, $template);
      $this->mappingStep->setErImportType($this->erImportType);

      $this->step = 'completed';
    }

    $form = $form + $this->mappingStep->getForm($form_state);

    $this->setEntityReferenceFields($this->mappingStep->getEntityReferenceFields());
    $this->setEntityReferenceFieldsOptions($this->mappingStep->getEntityReferenceFieldsOptions());

    $form['#attached']['drupalSettings']['gathercontent'] = $this->entityReferenceFieldsOptions;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#id'] == 'edit-submit') {
      if ($this->step === 'field_mapping') {
        $this->mappingStep->doValidate($form, $form_state);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#id'] == 'edit-submit') {
      /** @var \Drupal\gathercontent\Entity\MappingInterface $mapping */
      $mapping = $this->entity;
      $entityStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

      if ($this->step === 'field_mapping') {
        $this->step = 'er_mapping';
        $this->extractMappingData($form_state->getValues());
        if ($this->new) {
          $this->contentType = $form_state->getValue('content_type');
          $this->entityType = $form_state->getValue('entity_type');
        }
        else {
          $this->contentType = $mapping->getContentType();
          $this->entityType = $mapping->getMappedEntityType();
        }

        $this->erImportType = $form_state->getValue('er_mapping_type');

        if (empty($this->entityReferenceFields) || $this->erImportType === 'automatic') {
          $this->skip = TRUE;
        }

        if (!$this->skip) {
          $form_state->setRebuild(TRUE);
        }
      }

      if ($this->step === 'completed' || $this->skip) {
        $this->erImported = 0;
        // TODO: make it changeable.
        if (!$mapping->getMappedEntityType()) {
          $mapping->setMappedEntityType($this->entityType);
        }

        if ($this->new) {
          $mapping->setContentType($this->contentType);
          $bundles = $this->mappingStep->getBundles($this->entityType);
          $mapping->setContentTypeName($bundles[$this->contentType]);
        }
        $mapping->setData(serialize($this->mappingData));
        $mapping->setUpdatedDrupal(time());

        $template = $this->client->templateGet($mapping->getGathercontentTemplateId());

        $mapping->setTemplate(serialize($this->client->getBody(TRUE)));
        $mapping->save();

        // We need to modify field for checkboxes and field instance for radios.
        foreach ($template['related']->structure->groups as $group) {
          foreach ($group->fields as $gcField) {
            $local_field_id = $this->mappingData[$group->id]['elements'][$gcField->id];
            if ($gcField->type === 'choice_checkbox') {
              if (!empty($local_field_id)) {
                $local_options = [];
                foreach ($gcField->metaData->choiceFields['options'] as $option) {
                  $local_options[$option['optionId']] = $option['label'];
                }

                $local_id_array = explode('||', $local_field_id);
                $field_info = FieldConfig::load($local_id_array[count($local_id_array) - 1]);

                if ($field_info->getType() === 'entity_reference') {
                  if ($this->erImportType === 'automatic') {
                    $this->automaticTermsGenerator($field_info, $local_options, isset($this->mappingData[$group->id]['language']) ? $this->mappingData[$group->id]['language'] : LanguageInterface::LANGCODE_NOT_SPECIFIED);
                  }
                }
                else {
                  $field_info = $field_info->getFieldStorageDefinition();
                  // Make the change.
                  $field_info->setSetting('allowed_values', $local_options);
                  try {
                    $field_info->save();
                  }
                  catch (\Exception $e) {
                    // Log something.
                  }
                }
              }
            }
            elseif ($gcField->type === 'choice_radio') {
              if (!empty($local_field_id)) {
                $local_options = [];
                foreach ($gcField->metaData->choiceFields['options'] as $option) {
                  $local_options[$option['optionId']] = $option['label'];
                }

                $local_id_array = explode('||', $local_field_id);
                $field_info = FieldConfig::load($local_id_array[count($local_id_array) - 1]);

                if ($field_info->getType() === 'entity_reference') {
                  if ($this->erImportType === 'automatic') {
                    $this->automaticTermsGenerator($field_info, $local_options, isset($this->mappingData[$group->id]['language']) ? $this->mappingData[$group->id]['language'] : LanguageInterface::LANGCODE_NOT_SPECIFIED);
                  }
                }
                else {
                  $new_local_options = [];
                  foreach ($local_options as $name => $label) {
                    $new_local_options[] = $name . '|' . $label;
                  }
                  $entity = \Drupal::entityTypeManager()
                    ->getStorage('entity_form_display')
                    ->load('node.' . $mapping->getContentType() . '.default');
                  /** @var \Drupal\Core\Entity\Entity\EntityFormDisplay $entity */
                  $entity->getRenderer($field_info->getName())
                    ->setSetting('available_options', implode("\n", $new_local_options));
                }
              }
            }
          }
        }

        // If we went through mapping of er, we want to save them.
        if (!$this->skip) {
          $form_state->cleanValues();
          $fields = $form_state->getValues();
          // Prepare options for every language for every field.
          $options = $this->prepareOptions($template);

          foreach ($fields as $field_id => $tables) {
            $field_id = str_replace('--', '.', $field_id);
            $vid = $this->getVocabularyId($field_id);

            // Check if gathercontent_options_ids field exists.
            $this->gcOptionIdsFieldExists($vid);

            foreach ($tables as $table) {
              foreach ($table as $row) {
                $languages = $this->getAvailableLanguages($row);
                if ($this->erImportType === 'manual') {
                  $this->manualErImport($languages, $entityStorage, $row);
                }
                else {
                  $this->semiErImport($languages, $entityStorage, $row, $options, $vid);
                }
              }
            }
          }
        }

        if ($this->new) {
          $this->messenger()->addMessage($this->t('Mapping has been created.'));
        }
        else {
          $this->messenger()->addMessage($this->t('Mapping has been updated.'));
        }

        $this
          ->migrationDefinitionCreator
          ->setMapping($mapping)
          ->setMappingData($this->mappingData)
          ->createMigrationDefinition();

        if (!empty($this->entityReferenceFields)) {
          $this->messenger()->addMessage($this->formatPlural($this->erImported, '@count term was imported', '@count terms were imported'));
        }

        $form_state->setRedirect('entity.gathercontent_mapping.collection');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = ($this->new ? $this->t('Create mapping') : $this->t('Update mapping'));
    $actions['close'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
    ];
    unset($actions['delete']);
    return $actions;
  }

}
