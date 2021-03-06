<?php

namespace Drupal\gathercontent_ui\Form\MappingEditSteps;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;

/**
 * Class MappingStepEntityReference.
 *
 * @package Drupal\gathercontent_ui\Form\MappingEditSteps
 */
class MappingStepEntityReference extends MappingSteps {

  use StringTranslationTrait;

  /**
   * Type of import for entity reference fields.
   *
   * Values:
   * - automatic
   * - manual
   * - semiautomatic.
   *
   * @var string
   */
  protected $erImportType;

  /**
   * Sets the import type.
   *
   * @param string $value
   *   Import type text.
   */
  public function setErImportType($value) {
    $this->erImportType = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(FormStateInterface $formState) {
    $entityTypeManager = \Drupal::entityTypeManager();
    $form = [];

    foreach ($this->entityReferenceFields as $field => $gcMapping) {
      $field_id_array = explode('||', $field);
      $field_config = FieldConfig::load($field_id_array[count($field_id_array) - 1]);

      $options = [];
      $header = [];

      // Prepare options for every language.
      foreach ($gcMapping as $lang => $fieldSettings) {
        foreach ($this->template['related']->structure->groups as $group) {
          if ($group->id === $fieldSettings['tab']) {
            foreach ($group->fields as $gcField) {
              if ($gcField->id == $fieldSettings['name']) {
                $header[$lang] = $this->t('@field (@lang values)', [
                  '@field' => $gcField->label,
                  '@lang' => strtoupper($lang),
                ]);
                if (count($header) === 1 && $this->erImportType === 'manual') {
                  $header['terms'] = $this->t('Terms');
                }
                foreach ($gcField->metaData->choiceFields['options'] as $option) {
                  $options[$lang][$option['optionId']] = $option['label'];
                }
              }
            }
          }
        }
      }

      $term_options = [];
      // For manual mapping load terms from vocabulary.
      if ($this->erImportType === 'manual') {
        $settings = $field_config->getSetting('handler_settings');
        /** @var \Drupal\taxonomy\Entity\Term[] $terms */
        if (!empty($settings['auto_create_bundle'])) {
          $terms = $entityTypeManager->getStorage('taxonomy_term')
            ->loadByProperties(['vid' => $settings['auto_create_bundle']]);
        }
        else {
          $target = reset($settings['target_bundles']);
          $terms = $entityTypeManager->getStorage('taxonomy_term')
            ->loadByProperties(['vid' => $target]);
        }
        foreach ($terms as $term) {
          $term_options[$term->id()] = $term->getName();
        }

      }

      $field = str_replace('.', '--', $field);

      // Extract available languages and get the first and its options.
      $languages = array_keys($header);
      $first_language = array_shift($languages);
      $first_language_options = array_shift($options);
      // Delete terms from languages, it's not language.
      if (isset($languages[0]) && $languages[0] === 'terms') {
        unset($languages[0]);
      }

      $form[$field] = [
        '#tree' => TRUE,
      ];

      $form[$field]['title'] = [
        '#type' => 'html_tag',
        '#value' => $this->t('Field @field', ['@field' => $field_config->getLabel()]),
        '#tag' => 'h2',
      ];

      // Define table header.
      $form[$field]['table'] = [
        '#type' => 'table',
        '#header' => $header,
      ];

      // Each option in the first language is new row.
      // This solution is not dealing with situation when other languages has
      // more options than first language.
      $rows = 0;
      foreach ($first_language_options as $k => $option) {
        $form[$field]['table'][$rows][$first_language] = [
          '#type' => 'value',
          '#value' => $k,
          '#markup' => $option,
        ];

        if ($this->erImportType === 'manual') {
          $form[$field]['table'][$rows]['terms'] = [
            '#type' => 'select',
            '#options' => $term_options,
            '#title' => $this->t('Taxonomy term options'),
            '#title_display' => 'invisible',
            '#empty_option' => $this->t('- None -'),
          ];
        }

        foreach ($languages as $language) {
          $form[$field]['table'][$rows][$language] = [
            '#type' => 'select',
            '#options' => $options[$language],
            '#title' => $this->t('@lang options', ['@lang' => $language]),
            '#title_display' => 'invisible',
            '#empty_option' => $this->t('- None -'),
          ];
        }
        $rows++;
      }
    }

    return $form;
  }

}
