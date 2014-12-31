<?php

/**
 * @file
 * Contains \Drupal\system\Form\ModulesListConfirmForm.
 */

namespace Drupal\system\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a confirmation form for enabling modules with dependencies.
 */
class ModulesListConfirmForm extends ConfirmFormBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The expirable key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * An associative list of modules to enable or disable.
   *
   * @var array
   */
  protected $modules = array();

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * Constructs a ModulesListConfirmForm object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, KeyValueStoreExpirableInterface $key_value_expirable) {
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->keyValueExpirable = $key_value_expirable;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('keyvalue.expirable')->get('module_list')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Some required modules must be enabled');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('system.modules_list');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Continue');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Would you like to continue with the above?');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'system_modules_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $account = $this->currentUser()->id();
    $this->modules = $this->keyValueExpirable->get($account);

    // Redirect to the modules list page if the key value store is empty.
    if (!$this->modules) {
      return $this->redirect('system.modules_list');
    }

    $items = array();
    // Display a list of required modules that have to be installed as well but
    // were not manually selected.
    foreach ($this->modules['dependencies'] as $module => $dependencies) {
      $items[] = format_plural(count($dependencies), 'You must enable the @required module to install @module.', 'You must enable the @required modules to install @module.', array(
        '@module' => $this->modules['install'][$module],
        '@required' => implode(', ', $dependencies),
      ));
    }

    $form['message'] = array(
      '#theme' => 'item_list',
      '#items' => $items,
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove the key value store entry.
    $account = $this->currentUser()->id();
    $this->keyValueExpirable->delete($account);

    // Gets list of modules prior to install process.
    $before = $this->moduleHandler->getModuleList();

    // Install the given modules.
    if (!empty($this->modules['install'])) {
      // Don't catch the exception that this can throw for missing dependencies:
      // the form doesn't allow modules with unmet dependencies, so the only way
      // this can happen is if the filesystem changed between form display and
      // submit, in which case the user has bigger problems.
      $this->moduleInstaller->install(array_keys($this->modules['install']));
    }

    // Gets module list after install process, flushes caches and displays a
    // message if there are changes.
    if ($before != $this->moduleHandler->getModuleList()) {
      drupal_set_message($this->t('The configuration options have been saved.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
