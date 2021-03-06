<?php
/**
 * @file
 *
 * @author Mark Sonnabaum (http://drupal.org/user/75278)
 *
 */

require 'DrushAutoLoader.php';
require 'upstream.inc';

/**
 * Ipmlementation of hook_drush_help().
 */
function deploy_drush_help($section) {
}

/**
 * Implementation of hook_drush_command().
 */
function deploy_drush_command() {
  $items = array();
  $items['deploy'] = array(
    'description' => 'Deploying a site to a remote server.',
    'arguments' => array(
    ),
    'options' => array(
    ),
    'examples' => array(
    ),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUSH,
    'config' => 'deploy',
  );

  $loader = new DrushAutoLoader();
  $loader->registerNamespace('Drush', __DIR__);
  $loader->registerNamespace('Drush\\Deploy', __DIR__);
  $loader->register();

  foreach(\Drush\Deploy\Deploy::getCommands() as $command => $description) {
    $items['deploy-' . $command] = array(
      'description' => $description,
      'arguments' => array(),
      'options' => array(),
      'bootstrap' => DRUSH_BOOTSTRAP_DRUSH, // No bootstrap at all.
    );
  }

  foreach ($items as $command => &$item) {
    $item['callback'] = 'drush_deploy_callback';
    $item['callback arguments'] = array($command);
  }

  return $items;
}

/**
 * Ipmlementation of hook_drush_init().
 */
function deploy_drush_init() {
  // Load deploy config files.
  foreach (drush_context_names() as $context) {
    drush_load_config_file($context, _drush_config_file($context, 'deploy'));
  }
  $tasks = \Drush\Deploy\Deploy::getTasks();
}

function deploy_task() {
  $args = func_get_args();
  $name = array_shift($args);
  if ($args[count($args) - 1] instanceof Closure) {
    $work = array_pop($args);
  }
  else {
    $work = null;
  }
  Drush\Deploy\Deploy::$tasks[$name] = $work;
}

/**
 * Implementation of drush_hook_COMMAND().
 */
function drush_deploy_callback($command, $alias = NULL) {
  if (empty($alias)) {
    drush_set_error('DRUSH_DEPLOY_ERROR', dt("Must specify a site alias or group of site aliases!"));
    return;
  }
  $alias_settings = drush_sitealias_get_record($alias);
  $method = strpos($command, 'deploy-') === 0 ? substr($command, 7) : $command;
  $sites = array();
  if (isset($alias_settings['site-list'])) {
    foreach ($alias_settings['site-list'] as $alias) {
      drush_log('Loading alias '.$alias, 'success');
      $sites[] = drush_sitealias_get_record($alias);
    }
  }
  else {
    $sites[] = $alias_settings;
  }

  foreach ($sites as $site) {
    drush_set_config_options('alias', $site);
  }

  $deploy = new \Drush\Deploy\Deploy($sites);
  try {
    $deploy->runCommand($deploy, $method);
  }
  catch (CommandException $e) {
    drush_set_error($e->getMessage());
  }
}

function drush_deploy_transaction(&$obj = NULL, $commands) {
  if ($obj) {
    foreach ($commands as $i => $cmd) {
      try {
        $obj->runCommand($obj, $cmd);
      }
      //catch (CommandException $e) {
      catch (Exception $e) {
        drush_set_error("Error: '" . $e->getMessage() . "' starting rollback...");
        foreach ($commands as $ri => $rcmd) {
          try {
            $obj->runCommand($obj, $rcmd . '-rollback');
          }
          //catch (CommandException $e) {
          catch (Exception $e) {
            drush_set_error($e);
          }
          if ($ri >= $i) break;
          $ri++;
        }
        break;
      }
    }
  }
}
