<?php
namespace DrushDeploy;
class Deploy {
  public $sites;
  public $application;
  public $repository;
  public $deploy_via;
  public $deploy_to;
  public $revision;
  public $maintenance_basename;
  public $real_revision;
  public $strategy;
  public $release_name;
  public $version_dir;
  public $shared_dir;
  public $shared_children;
  public $current_dir;
  public $releases_path;
  public $shared_path;
  public $current_path;
  public $release_path;
  public $releases;
  public $current_release;
  public $previous_release;
  public $current_revision;
  public $latest_revision;
  public $previous_revision;
  public $run_method;
  public $latest_release;

  function __construct($sites) {
    // These variables MUST be set in the client config files. If they are not set,
    // the deploy will fail with an error.
    $app = drush_get_option('application', NULL);
    if (!$this->application = drush_get_option('application', NULL)) {
      $this->abort("Please specify the name of your application");
    }
    if (!$this->repository = drush_get_option('deploy-repository', NULL)) {
      $this->abort("Please specify the repository that houses your application's code");
    }

    $this->git = new Git($this);
    $this->deploy_via = drush_get_option('deploy_via', 'Checkout');

    $this->deploy_to = drush_get_option('deploy-to', '~/deploy');
    $this->revision = drush_get_option('branch', 'HEAD');

    // Maintenance base filename
    $this->maintenance_basename = drush_get_option('maintenance_basename', 'maintenance');
    $this->real_revision = $this->git->query_revision($this->revision, FALSE);
    $class = 'DrushDeploy\Strategy\\' . $this->deploy_via;
    $this->strategy = new $class($this);

    $datetime = new \DateTime("now", new \DateTimeZone("UTC"));
    $this->release_name = $datetime->format("YmdHis");

    $this->sites = $sites;
    $this->current_dir = "current";
    $this->releases_path = $this->deploy_to . '/releases';
    $this->shared_path = $this->deploy_to . '/shared';
    $this->current_path = $this->deploy_to . '/' . $this->current_dir;
    $this->release_path = $this->releases_path . '/' .  $this->release_name;

    $this->run_method = drush_get_option('use_sudo', TRUE) ? 'sudo': 'run';
    $this->no_release = drush_get_option('no_release', FALSE);
  }

  function latest_release() {
    if (!$this->latest_release) {
      $this->latest_release = file_exists($this->release_name) ? $this->release_path : $this->current_release();
    }
    return $this->latest_release;
  }

  function releases() {
    if ($this->no_release) return FALSE;

    if (!$this->releases) {
      $output = $this->capture("ls -x " . $this->releases_path, $this->sites[0]);
      $output = implode(" ", $output);
      $this->releases = preg_split("/\s+/", $output);
    }
    return $this->releases;
  }

  function current_release() {
    if (!$this->current_release) {
      $releases = $this->releases();
      $this->current_release = $this->releases_path . '/' . end($releases);
    }
    return $this->current_release;
  }

  function previous_release() {
    if (!$this->previous_release) {
      $releases = $this->releases();
      $total = count($releases);
      $this->previous_release = $total > 1 ? $this->releases_path . '/' . $releases[$total - 2] : NULL;
    }
    return $this->previous_release;
  }

  function current_revision() {
    if ($this->no_release) return FALSE;
    return $this->capture("cat $this->current_path()/REVISION", $this->sites[0]);
  }

  function latest_revision() {
    if (!$this->no_release) return FALSE;
    return $this->capture("cat $this->current_release()/REVISION", $this->sites[0]);
  }

  function previous_revision() {
    if (!$this->no_release) return FALSE;
    if ($this->previous_release()) {
      return $this->capture("cat $this->previous_release()/REVISION", $this->sites[0]);
    }
  }

  /**
   * Deploys your project. This calls both `update' and `restart'. Note that \
   * this will generally only work for applications that have already been deployed \
   * once. For a "cold" deploy, you'll want to take a look at the `deploy:cold' \
   * task, which handles the cold start specifically.
   */
  function deploy() {
    $this->update();
    //$this->restart();
  }

  /**
   * Prepares one or more servers for deployment.
   * It is safe to run this task on servers that have already been set up; it
   * will not destroy any deployed revisions or data.
   */
  function setup() {
    $dirs = array($this->releases_path, $this->shared_path);
    $dirs_str = implode(' ', $dirs);
    $this->run('mkdir -p ' . $dirs_str . ' && chmod g+w ' . $dirs_str);
  }

  function run($command, $args = array(), $check_no_release = FALSE) {
    // TODO: make this parallel.
    foreach ($this->sites as $site) {
      $cmd = $this->__buildCommand($command, $site);
      drush_print('CMD: ' . $cmd);
      try {
        if (!drush_shell_exec($cmd, $args)) {
          $output = drush_shell_exec_output();
          drush_print_r($output);
          throw new CommandException(implode("\n", drush_shell_exec_output()));
        }
      }
      catch (CommandException $e) {
        drush_set_error($e);
      }
    }
  }

  function capture($command, $site, $check_no_release = FALSE) {
    if ($site == 'local') {
      $cmd = $command;
    }
    else {
      $cmd = $this->__buildCommand($command, $site);
    }
    drush_print('CMD: ' . $cmd);
    if (drush_shell_exec($cmd)) {
      return drush_shell_exec_output();
    }
  }

  //function __buildCommand($command, $args = array(), $site) {
  function __buildCommand($command, $site) {
    // TODO: make this parallel.
    $hostname = isset($site['remote-host']) ? drush_escapeshellarg($site['remote-host'], "LOCAL") : null;
    $username = isset($site['remote-user']) ? drush_escapeshellarg($site['remote-user'], "LOCAL") . "@" : '';
    $ssh_options = isset($site['ssh-options']) ? $site['ssh-options'] : drush_get_option('ssh-options', "-o PasswordAuthentication=no");

    if (!is_null($hostname)) {
      $cmd = "ssh " . $ssh_options . " " . $username . $hostname . " " . drush_escapeshellarg($command, "LOCAL") . ' 2>&1';
    }
    return $cmd;
  }

  //function __buildRemoteCommand($command, $args = array(), $site) {
  function __buildRemoteCommand($command, $site) {
    // TODO: make this parallel.
    $hostname = isset($site['remote-host']) ? drush_escapeshellarg($site['remote-host'], "LOCAL") : null;
    $username = isset($site['remote-user']) ? drush_escapeshellarg($site['remote-user'], "LOCAL") . "@" : '';
    $ssh_options = isset($site['ssh-options']) ? $site['ssh-options'] : drush_get_option('ssh-options', "-o PasswordAuthentication=no");

    if (!is_null($hostname)) {
      $cmd = "ssh " . $ssh_options . " " . $username . $hostname . " " . drush_escapeshellarg($command, "LOCAL") . ' 2>&1';
    }
    return $cmd;
  }

  /**
   * Copies your project and updates the symlink. It does this in a \
   * transaction, so that if either `update_code' or `symlink' fail, all \
   * changes made to the remote servers will be rolled back, leaving your \
   * system in the same state it was in before `update' was invoked. Usually, \
   * you will want to call `deploy' instead of `update', but `update' can be \
   * handy if you want to deploy, but not immediately restart your application.
   */
  function update() {
    drush_deploy_transaction($this, array(
      'update_code',
      'symlink'
    ));
  }

  /**
   * Copies your project to the remote servers. This is the first stage \
   * of any deployment; moving your updated code and assets to the deployment \
   * servers. You will rarely call this task directly, however; instead, you \
   * should call the `deploy' task (to do a complete deploy) or the `update' \
   * task (if you want to perform the `restart' task separately).
   *
   * You will need to make sure you set the :scm variable to the source \
   * control software you are using (it defaults to :subversion), and the \
   * :deploy_via variable to the strategy you want to use to deploy (it \
   * defaults to :checkout).
   */
  function update_code() {
    $this->strategy->deploy();
  }

  function update_code_rollback() {
    $this->run("rm -rf #{release_path}; true");
  }

  function strategyCommand() {
    switch ($this->deploy_via) {
    case 'checkout':
      $command = $this->strategyCommand() . ' && ' . $this->mark();
      break;
    case 'remotecache':
      $this->updateRepositoryCache();
      $this->copyRepositoryCache();
      break;
    }
    return $command;
    //finalize_update
  }

  function finalize_update() {
    return;
  }

  function symlink() {
    //$this->run('iidf -h');
    $cmd = sprintf("rm -f %s && ln -s %s %s", $this->current_path, $this->latest_release(), $this->current_path);
    $this->run($cmd);
  }

  function symlink_rollback() {
    if ($this->previous_release) {
      $cmd = sprintf("echo 'rm -f %s; ln -s %s %s; true'", $this->current_path, $this->previous_release, $this->current_path);
      $this->run($cmd);
    }
    else {
      drush_log(dt("no previous release to rollback to, rollback of symlink skipped"), 'error');
    }
  }

  /**
   * Rolls back to a previous version and restarts. This is handy if you ever
   * discover that you've deployed a lemon; `drush deploy-rollback' and you're right
   * back where you were, on the previously deployed version.
   */
  function rollback() {
    $this->__rollback_revision();
    $this->__rollback_cleanup();
  }

  /**
   * [internal] Points the current symlink at the previous revision.
   * This is called by the rollback sequence, and should rarely (if
   * ever) need to be called directly.
   */
  function __rollback_revision() {
    if ($prev_release = $this->previous_release()) {
      $this->run(sprintf("rm %s; ln -s %s %s", $this->current_path, $prev_release, $this->current_path));
    }
    else {
     drush_set_error(dt("Could not rollback the code because there is no prior release"));
    }
  }

  /**
   * [internal] Removes the most recently deployed release.
   * This is called by the rollback sequence, and should rarely
   * (if ever) need to be called directly.
   */
  function __rollback_cleanup() {
    $c = sprintf("if [ `readlink %s` != %s ]; then rm -rf %s; fi", $this->current_path, $this->current_release(), $this->current_release());
    $this->run($c);
  }

  /**
   * Rolls back to the previously deployed version. The `current' symlink will
   * be updated to point at the previously deployed version, and then the
   * current release will be removed from the servers. You'll generally want
   * to call `rollback' instead, as it performs a `restart' as well.
   */
  public function __rollback_code() {
    $this->__rollback_revision();
    $this->__rollback_cleanup();
  }

  /**
   * Clean up old releases. By default, the last 5 releases are kept on each
   * server (though you can change this with the keep_releases variable). All
   * other deployed revisions are removed from the servers. By default, this
   * will use sudo to clean up the old releases, but if sudo is not available
   * for your environment, set the :use_sudo variable to false instead.
   */
  public function cleanup() {
    $count = drush_get_option('keep_releases', 5);
    $total = count($this->releases());
    if ($count >= count($this->releases())) {
      drush_log("no old releases to clean up", 'error');
    }
    else {
      drush_log("keeping " . $count . " of " . count($this->releases()) . " deployed releases");
      $directories = array_slice($this->releases(), 0, $total - $count);
      $directories = $this->releases_path . '/' . implode(" " . $this->releases_path . '/', $directories);

      $this->run("rm -rf $directories");
    }
  }

  /**
   * Displays the commits since your last deploy. This is good for a summary
   * of the changes that have occurred since the last deploy.
   */
  function pending() {
   $from = $this->git->next_revision($this->current_revision);
   system($this->git->log($from, 'local'));
  }

  /**
   * @param $message
   * @return void
   */
  function abort($message) {
    drush_set_error('DRUSH_DEPLOY_ERROR', $message);
  }
}

class CommandException extends \Exception {}
