<?php

declare(strict_types=1);

namespace Drupal\aqto_static\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Provides the Aqto Static form.
 */
final class GenerateStaticSiteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aqto_static_generate_static_site';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $node_options = [];
    $nodes = Node::loadMultiple();
    foreach ($nodes as $node) {
      $node_options[$node->id()] = $node->label();
    }

    $form['nodes'] = [
      '#type' => 'select',
      '#title' => $this->t('Select nodes'),
      '#options' => $node_options,
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    $form['directory_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Static directory name'),
      '#description' => $this->t('Enter the name of the directory under sites/default/files where files will be stored.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Generate static site'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nodes = $form_state->getValue('nodes');
    $directory_name = $form_state->getValue('directory_name');
    $this->getStaticGenerator()->generateStaticSite($nodes, $directory_name);
    $this->messenger()->addStatus($this->t('Static site generation initiated.'));
    // $form_state->setRedirect('<front>');
  }

  /**
   * Helper function to get the static generator service.
   */
  protected function getStaticGenerator() {
    return \Drupal::service('aqto_static.static_generator');
  }

}
