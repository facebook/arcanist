<?php

/**
 * Implements a runnable command, like "arc diff" or "arc help".
 *
 * = Managing Conduit =
 *
 * Workflows have the builtin ability to open a Conduit connection to a
 * Phabricator installation, so methods can be invoked over the API. Workflows
 * may either not need this (e.g., "help"), or may need a Conduit but not
 * authentication (e.g., calling only public APIs), or may need a Conduit and
 * authentication (e.g., "arc diff").
 *
 * To specify that you need an //unauthenticated// conduit, override
 * @{method:requiresConduit} to return ##true##. To specify that you need an
 * //authenticated// conduit, override @{method:requiresAuthentication} to
 * return ##true##. You can also manually invoke @{method:establishConduit}
 * and/or @{method:authenticateConduit} later in a workflow to upgrade it.
 * Once a conduit is open, you can access the client by calling
 * @{method:getConduit}, which allows you to invoke methods. You can get
 * verified information about the user identity by calling @{method:getUserPHID}
 * or @{method:getUserName} after authentication occurs.
 *
 * = Scratch Files =
 *
 * Arcanist workflows can read and write 'scratch files', which are temporary
 * files stored in the project that persist across commands. They can be useful
 * if you want to save some state, or keep a copy of a long message the user
 * entered if something goes wrong.
 *
 *
 * @task  conduit   Conduit
 * @task  scratch   Scratch Files
 * @task  phabrep   Phabricator Repositories
 *
 * @stable
 */
abstract class ArcanistBaseWorkflow extends Phobject {

  const COMMIT_DISABLE = 0;
  const COMMIT_ALLOW = 1;
  const COMMIT_ENABLE = 2;

  const AUTO_COMMIT_TITLE = 'Automatic commit by arc';

  private $commitMode = self::COMMIT_DISABLE;

  private $conduit;
  private $conduitURI;
  private $conduitCredentials;
  private $conduitAuthenticated;
  private $forcedConduitVersion;
  private $conduitTimeout;

  private $userPHID;
  private $userName;
  private $repositoryAPI;
  private $configurationManager;
  private $workingCopy;
  private $arguments;
  private $passedArguments;
  private $command;

  private $stashed;
  private $shouldAmend;

  private $projectInfo;
  private $repositoryInfo;
  private $repositoryReasons;

  private $arcanistConfiguration;
  private $parentWorkflow;
  private $workingDirectory;
  private $repositoryVersion;
  private $workingRevision;

  private $changeCache = array();


  public function __construct() {
    $this->workingRevision = 'HEAD';
  }


  abstract public function run();

  /**
   * Finalizes any cleanup operations that need to occur regardless of
   * whether the command succeeded or failed.
   */
  public function finalize() {
    $this->finalizeWorkingCopy();
  }

  /**
   * Return the command used to invoke this workflow from the command like,
   * e.g. "help" for @{class:ArcanistHelpWorkflow}.
   *
   * @return string   The command a user types to invoke this workflow.
   */
  abstract public function getWorkflowName();

  /**
   * Return console formatted string with all command synopses.
   *
   * @return string  6-space indented list of available command synopses.
   */
  abstract public function getCommandSynopses();

  /**
   * Return console formatted string with command help printed in `arc help`.
   *
   * @return string  10-space indented help to use the command.
   */
  abstract public function getCommandHelp();


/* -(  Conduit  )------------------------------------------------------------ */


  /**
   * Set the URI which the workflow will open a conduit connection to when
   * @{method:establishConduit} is called. Arcanist makes an effort to set
   * this by default for all workflows (by reading ##.arcconfig## and/or the
   * value of ##--conduit-uri##) even if they don't need Conduit, so a workflow
   * can generally upgrade into a conduit workflow later by just calling
   * @{method:establishConduit}.
   *
   * You generally should not need to call this method unless you are
   * specifically overriding the default URI. It is normally sufficient to
   * just invoke @{method:establishConduit}.
   *
   * NOTE: You can not call this after a conduit has been established.
   *
   * @param string  The URI to open a conduit to when @{method:establishConduit}
   *                is called.
   * @return this
   * @task conduit
   */
  final public function setConduitURI($conduit_uri) {
    if ($this->conduit) {
      throw new Exception(
        "You can not change the Conduit URI after a conduit is already open.");
    }
    $this->conduitURI = $conduit_uri;
    return $this;
  }

  /**
   * Returns the URI the conduit connection within the workflow uses.
   *
   * @return string
   * @task conduit
   */
  final public function getConduitURI() {
    return $this->conduitURI;
  }

  /**
   * Open a conduit channel to the server which was previously configured by
   * calling @{method:setConduitURI}. Arcanist will do this automatically if
   * the workflow returns ##true## from @{method:requiresConduit}, or you can
   * later upgrade a workflow and build a conduit by invoking it manually.
   *
   * You must establish a conduit before you can make conduit calls.
   *
   * NOTE: You must call @{method:setConduitURI} before you can call this
   * method.
   *
   * @return this
   * @task conduit
   */
  final public function establishConduit() {
    if ($this->conduit) {
      return $this;
    }

    if (!$this->conduitURI) {
      throw new Exception(
        "You must specify a Conduit URI with setConduitURI() before you can ".
        "establish a conduit.");
    }

    $this->conduit = new ConduitClient($this->conduitURI);

    if ($this->conduitTimeout) {
      $this->conduit->setTimeout($this->conduitTimeout);
    }

    $user = $this->getConfigFromAnySource('http.basicauth.user');
    $pass = $this->getConfigFromAnySource('http.basicauth.pass');
    if ($user !== null && $pass !== null) {
      $this->conduit->setBasicAuthCredentials($user, $pass);
    }

    return $this;
  }

  final public function getConfigFromAnySource($key) {
    return $this->configurationManager->getConfigFromAnySource($key);
  }


  /**
   * Set credentials which will be used to authenticate against Conduit. These
   * credentials can then be used to establish an authenticated connection to
   * conduit by calling @{method:authenticateConduit}. Arcanist sets some
   * defaults for all workflows regardless of whether or not they return true
   * from @{method:requireAuthentication}, based on the ##~/.arcrc## and
   * ##.arcconf## files if they are present. Thus, you can generally upgrade a
   * workflow which does not require authentication into an authenticated
   * workflow by later invoking @{method:requireAuthentication}. You should not
   * normally need to call this method unless you are specifically overriding
   * the defaults.
   *
   * NOTE: You can not call this method after calling
   * @{method:authenticateConduit}.
   *
   * @param dict  A credential dictionary, see @{method:authenticateConduit}.
   * @return this
   * @task conduit
   */
  final public function setConduitCredentials(array $credentials) {
    if ($this->isConduitAuthenticated()) {
      throw new Exception(
        "You may not set new credentials after authenticating conduit.");
    }

    $this->conduitCredentials = $credentials;
    return $this;
  }


  /**
   * Force arc to identify with a specific Conduit version during the
   * protocol handshake. This is primarily useful for development (especially
   * for sending diffs which bump the client Conduit version), since the client
   * still actually speaks the builtin version of the protocol.
   *
   * Controlled by the --conduit-version flag.
   *
   * @param int Version the client should pretend to be.
   * @return this
   * @task conduit
   */
  public function forceConduitVersion($version) {
    $this->forcedConduitVersion = $version;
    return $this;
  }


  /**
   * Get the protocol version the client should identify with.
   *
   * @return int Version the client should claim to be.
   * @task conduit
   */
  public function getConduitVersion() {
    return nonempty($this->forcedConduitVersion, 6);
  }


  /**
   * Override the default timeout for Conduit.
   *
   * Controlled by the --conduit-timeout flag.
   *
   * @param float Timeout, in seconds.
   * @return this
   * @task conduit
   */
  public function setConduitTimeout($timeout) {
    $this->conduitTimeout = $timeout;
    if ($this->conduit) {
      $this->conduit->setConduitTimeout($timeout);
    }
    return $this;
  }


  /**
   * Open and authenticate a conduit connection to a Phabricator server using
   * provided credentials. Normally, Arcanist does this for you automatically
   * when you return true from @{method:requiresAuthentication}, but you can
   * also upgrade an existing workflow to one with an authenticated conduit
   * by invoking this method manually.
   *
   * You must authenticate the conduit before you can make authenticated conduit
   * calls (almost all calls require authentication).
   *
   * This method uses credentials provided via @{method:setConduitCredentials}
   * to authenticate to the server:
   *
   *    - ##user## (required) The username to authenticate with.
   *    - ##certificate## (required) The Conduit certificate to use.
   *    - ##description## (optional) Description of the invoking command.
   *
   * Successful authentication allows you to call @{method:getUserPHID} and
   * @{method:getUserName}, as well as use the client you access with
   * @{method:getConduit} to make authenticated calls.
   *
   * NOTE: You must call @{method:setConduitURI} and
   * @{method:setConduitCredentials} before you invoke this method.
   *
   * @return this
   * @task conduit
   */
  final public function authenticateConduit() {
    if ($this->isConduitAuthenticated()) {
      return $this;
    }

    $this->establishConduit();
    $credentials = $this->conduitCredentials;

    try {
      if (!$credentials) {
        throw new Exception(
          "Set conduit credentials with setConduitCredentials() before ".
          "authenticating conduit!");
      }

      if (empty($credentials['user'])) {
        throw new ConduitClientException('ERR-INVALID-USER',
                                         'Empty user in credentials.');
      }
      if (empty($credentials['certificate'])) {
        throw new ConduitClientException('ERR-NO-CERTIFICATE',
                                         'Empty certificate in credentials.');
      }

      $description = idx($credentials, 'description', '');
      $user        = $credentials['user'];
      $certificate = $credentials['certificate'];

      $connection = $this->getConduit()->callMethodSynchronous(
        'conduit.connect',
        array(
          'client'              => 'arc',
          'clientVersion'       => $this->getConduitVersion(),
          'clientDescription'   => php_uname('n').':'.$description,
          'user'                => $user,
          'certificate'         => $certificate,
          'host'                => $this->conduitURI,
        ));
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-NO-CERTIFICATE' ||
          $ex->getErrorCode() == 'ERR-INVALID-USER') {
        $conduit_uri = $this->conduitURI;
        $message =
          "\n".
          phutil_console_format(
            "YOU NEED TO __INSTALL A CERTIFICATE__ TO LOGIN TO PHABRICATOR").
          "\n\n".
          phutil_console_format(
            "    To do this, run: **arc install-certificate**").
          "\n\n".
          "The server '{$conduit_uri}' rejected your request:".
          "\n".
          $ex->getMessage();
        throw new ArcanistUsageException($message);
      } else if ($ex->getErrorCode() == 'NEW-ARC-VERSION') {

        // Cleverly disguise this as being AWESOME!!!

        echo phutil_console_format("**New Version Available!**\n\n");
        echo phutil_console_wrap($ex->getMessage());
        echo "\n\n";
        echo "In most cases, arc can be upgraded automatically.\n";

        $ok = phutil_console_confirm(
          "Upgrade arc now?",
          $default_no = false);
        if (!$ok) {
          throw $ex;
        }

        $root = dirname(phutil_get_library_root('arcanist'));

        chdir($root);
        $err = phutil_passthru('%s upgrade', $root.'/bin/arc');
        if (!$err) {
          echo "\nTry running your arc command again.\n";
        }
        exit(1);
      } else {
        throw $ex;
      }
    }

    $this->userName = $user;
    $this->userPHID = $connection['userPHID'];

    $this->conduitAuthenticated = true;

    return $this;
  }

  /**
   * @return bool True if conduit is authenticated, false otherwise.
   * @task conduit
   */
  final protected function isConduitAuthenticated() {
    return (bool)$this->conduitAuthenticated;
  }


  /**
   * Override this to return true if your workflow requires a conduit channel.
   * Arc will build the channel for you before your workflow executes. This
   * implies that you only need an unauthenticated channel; if you need
   * authentication, override @{method:requiresAuthentication}.
   *
   * @return bool True if arc should build a conduit channel before running
   *              the workflow.
   * @task conduit
   */
  public function requiresConduit() {
    return false;
  }


  /**
   * Override this to return true if your workflow requires an authenticated
   * conduit channel. This implies that it requires a conduit. Arc will build
   * and authenticate the channel for you before the workflow executes.
   *
   * @return bool True if arc should build an authenticated conduit channel
   *              before running the workflow.
   * @task conduit
   */
  public function requiresAuthentication() {
    return false;
  }


  /**
   * Returns the PHID for the user once they've authenticated via Conduit.
   *
   * @return phid Authenticated user PHID.
   * @task conduit
   */
  final public function getUserPHID() {
    if (!$this->userPHID) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires authentication, override ".
        "requiresAuthentication() to return true.");
    }
    return $this->userPHID;
  }

  /**
   * Deprecated. See @{method:getUserPHID}.
   *
   * @deprecated
   */
  final public function getUserGUID() {
    phutil_deprecated(
      'ArcanistBaseWorkflow::getUserGUID',
      'This method has been renamed to getUserPHID().');
    return $this->getUserPHID();
  }

  /**
   * Return the username for the user once they've authenticated via Conduit.
   *
   * @return string Authenticated username.
   * @task conduit
   */
  final public function getUserName() {
    return $this->userName;
  }


  /**
   * Get the established @{class@libphutil:ConduitClient} in order to make
   * Conduit method calls. Before the client is available it must be connected,
   * either implicitly by making @{method:requireConduit} or
   * @{method:requireAuthentication} return true, or explicitly by calling
   * @{method:establishConduit} or @{method:authenticateConduit}.
   *
   * @return @{class@libphutil:ConduitClient} Live conduit client.
   * @task conduit
   */
  final public function getConduit() {
    if (!$this->conduit) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a Conduit, override ".
        "requiresConduit() to return true.");
    }
    return $this->conduit;
  }


  public function setArcanistConfiguration(
    ArcanistConfiguration $arcanist_configuration) {

    $this->arcanistConfiguration = $arcanist_configuration;
    return $this;
  }

  public function getArcanistConfiguration() {
    return $this->arcanistConfiguration;
  }

  public function setConfigurationManager(
    ArcanistConfigurationManager $arcanist_configuration_manager) {

    $this->configurationManager = $arcanist_configuration_manager;
    return $this;
  }

  public function getConfigurationManager() {
    return $this->configurationManager;
  }

  public function requiresWorkingCopy() {
    return false;
  }

  public function desiresWorkingCopy() {
    return false;
  }

  public function requiresRepositoryAPI() {
    return false;
  }

  public function desiresRepositoryAPI() {
    return false;
  }

  public function setCommand($command) {
    $this->command = $command;
    return $this;
  }

  public function getCommand() {
    return $this->command;
  }

  public function getArguments() {
    return array();
  }

  public function setWorkingRevision($revision)
  {
    $this->workingRevision = $revision;
    if ($this->repositoryAPI) {
      $this->repositoryAPI->setWorkingRevision($revision);
    }
    return $this;
  }

  public function setWorkingDirectory($working_directory) {
    $this->workingDirectory = $working_directory;
    return $this;
  }

  public function getWorkingDirectory() {
    return $this->workingDirectory;
  }

  private function setParentWorkflow($parent_workflow) {
    $this->parentWorkflow = $parent_workflow;
    return $this;
  }

  protected function getParentWorkflow() {
    return $this->parentWorkflow;
  }

  public function buildChildWorkflow($command, array $argv) {
    $arc_config = $this->getArcanistConfiguration();
    $workflow = $arc_config->buildWorkflow($command);
    $workflow->setParentWorkflow($this);
    $workflow->setCommand($command);
    $workflow->setConfigurationManager($this->getConfigurationManager());

    if ($this->repositoryAPI) {
      $workflow->setRepositoryAPI($this->repositoryAPI);
    }

    if ($this->userPHID) {
      $workflow->userPHID = $this->getUserPHID();
      $workflow->userName = $this->getUserName();
    }

    if ($this->conduit) {
      $workflow->conduit = $this->conduit;
    }

    if ($this->workingCopy) {
      $workflow->setWorkingCopy($this->workingCopy);
    }

    $workflow->setArcanistConfiguration($arc_config);

    $workflow->parseArguments(array_values($argv));

    return $workflow;
  }

  public function getArgument($key, $default = null) {
    return idx($this->arguments, $key, $default);
  }

  public function getPassedArguments() {
    return $this->passedArguments;
  }

  final public function getCompleteArgumentSpecification() {
    $spec = $this->getArguments();
    $arc_config = $this->getArcanistConfiguration();
    $command = $this->getCommand();
    $spec += $arc_config->getCustomArgumentsForCommand($command);
    return $spec;
  }

  public function parseArguments(array $args) {
    $this->passedArguments = $args;

    $spec = $this->getCompleteArgumentSpecification();

    $dict = array();

    $more_key = null;
    if (!empty($spec['*'])) {
      $more_key = $spec['*'];
      unset($spec['*']);
      $dict[$more_key] = array();
    }

    $short_to_long_map = array();
    foreach ($spec as $long => $options) {
      if (!empty($options['short'])) {
        $short_to_long_map[$options['short']] = $long;
      }
    }

    foreach ($spec as $long => $options) {
      if (!empty($options['repeat'])) {
        $dict[$long] = array();
      }
    }

    $more = array();
    for ($ii = 0; $ii < count($args); $ii++) {
      $arg = $args[$ii];
      $arg_name = null;
      $arg_key = null;
      if ($arg == '--') {
        $more = array_merge(
          $more,
          array_slice($args, $ii + 1));
        break;
      } else if (!strncmp($arg, '--', 2)) {
        $arg_key = substr($arg, 2);
        if (!array_key_exists($arg_key, $spec)) {
          $corrected = ArcanistConfiguration::correctArgumentSpelling(
            $arg_key,
            array_keys($spec));
          if (count($corrected) == 1) {
            PhutilConsole::getConsole()->writeErr(
              pht(
                "(Assuming '%s' is the British spelling of '%s'.)",
                '--'.$arg_key,
                '--'.head($corrected))."\n");
            $arg_key = head($corrected);
          } else {
            throw new ArcanistUsageException(pht(
              "Unknown argument '%s'. Try 'arc help'.",
              $arg_key));
          }
        }
      } else if (!strncmp($arg, '-', 1)) {
        $arg_key = substr($arg, 1);
        if (empty($short_to_long_map[$arg_key])) {
          throw new ArcanistUsageException(pht(
            "Unknown argument '%s'. Try 'arc help'.",
            $arg_key));
        }
        $arg_key = $short_to_long_map[$arg_key];
      } else {
        $more[] = $arg;
        continue;
      }

      $options = $spec[$arg_key];
      if (empty($options['param'])) {
        $dict[$arg_key] = true;
      } else {
        if ($ii == count($args) - 1) {
          throw new ArcanistUsageException(pht(
            "Option '%s' requires a parameter.",
            $arg));
        }
        if (!empty($options['repeat'])) {
          $dict[$arg_key][] = $args[$ii + 1];
        } else {
          $dict[$arg_key] = $args[$ii + 1];
        }
        $ii++;
      }
    }

    if ($more) {
      if ($more_key) {
        $dict[$more_key] = $more;
      } else {
        $example = reset($more);
        throw new ArcanistUsageException(pht(
          "Unrecognized argument '%s'. Try 'arc help'.",
          $example));
      }
    }

    foreach ($dict as $key => $value) {
      if (empty($spec[$key]['conflicts'])) {
        continue;
      }
      foreach ($spec[$key]['conflicts'] as $conflict => $more) {
        if (isset($dict[$conflict])) {
          if ($more) {
            $more = ': '.$more;
          } else {
            $more = '.';
          }
          // TODO: We'll always display these as long-form, when the user might
          // have typed them as short form.
          throw new ArcanistUsageException(
            "Arguments '--{$key}' and '--{$conflict}' are mutually exclusive".
            $more);
        }
      }
    }

    $this->arguments = $dict;

    $this->didParseArguments();

    return $this;
  }

  protected function didParseArguments() {
    // Override this to customize workflow argument behavior.
  }

  public function getWorkingCopy() {
    $working_copy = $this->getConfigurationManager()->getWorkingCopyIdentity();
    if (!$working_copy) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a working copy, override ".
        "requiresWorkingCopy() to return true.");
    }
    return $working_copy;
  }

  public function setWorkingCopy(
    ArcanistWorkingCopyIdentity $working_copy) {
    $this->workingCopy = $working_copy;
    return $this;
  }

  public function setRepositoryAPI($api) {
    $this->repositoryAPI = $api;
    $api->setWorkingRevision($this->workingRevision);
    return $this;
  }

  public function getRepositoryAPI() {
    if (!$this->repositoryAPI) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a Repository API, override ".
        "requiresRepositoryAPI() to return true.");
    }
    return $this->repositoryAPI;
  }

  protected function shouldRequireCleanUntrackedFiles() {
    return empty($this->arguments['allow-untracked']);
  }

  public function setCommitMode($mode) {
    $this->commitMode = $mode;
    return $this;
  }

  public function finalizeWorkingCopy() {
    if ($this->stashed) {
      $api = $this->getRepositoryAPI();
      $api->unstashChanges();
      echo pht('Restored stashed changes to the working directory.') . "\n";
    }
  }

  public function requireCleanWorkingCopy() {
    $api = $this->getRepositoryAPI();

    $must_commit = array();

    $working_copy_desc = phutil_console_format(
      "  Working copy: __%s__\n\n",
      $api->getPath());

    $untracked = $api->getUntrackedChanges();
    if ($this->shouldRequireCleanUntrackedFiles()) {

      if (!empty($untracked)) {
        echo "You have untracked files in this working copy.\n\n".
             $working_copy_desc.
             "  Untracked files in working copy:\n".
             "    ".implode("\n    ", $untracked)."\n\n";

        if ($api instanceof ArcanistGitAPI) {
          echo phutil_console_wrap(
            "Since you don't have '.gitignore' rules for these files and have ".
            "not listed them in '.git/info/exclude', you may have forgotten ".
            "to 'git add' them to your commit.\n");
        } else if ($api instanceof ArcanistSubversionAPI) {
          echo phutil_console_wrap(
            "Since you don't have 'svn:ignore' rules for these files, you may ".
            "have forgotten to 'svn add' them.\n");
        } else if ($api instanceof ArcanistMercurialAPI) {
          echo phutil_console_wrap(
            "Since you don't have '.hgignore' rules for these files, you ".
            "may have forgotten to 'hg add' them to your commit.\n");
        }

        if ($this->askForAdd($untracked)) {
          $api->addToCommit($untracked);
          $must_commit += array_flip($untracked);
        } else if ($this->commitMode == self::COMMIT_DISABLE) {
          $prompt = $this->getAskForAddPrompt($untracked);
          if (phutil_console_confirm($prompt)) {
            throw new ArcanistUsageException(pht(
              "Add these files and then run 'arc %s' again.",
              $this->getWorkflowName()));
          }
        }

      }
    }

    // NOTE: this is a subversion-only concept.
    $incomplete = $api->getIncompleteChanges();
    if ($incomplete) {
      throw new ArcanistUsageException(
        "You have incompletely checked out directories in this working copy. ".
        "Fix them before proceeding.\n\n".
        $working_copy_desc.
        "  Incomplete directories in working copy:\n".
        "    ".implode("\n    ", $incomplete)."\n\n".
        "You can fix these paths by running 'svn update' on them.");
    }

    $conflicts = $api->getMergeConflicts();
    if ($conflicts) {
      throw new ArcanistUsageException(
        "You have merge conflicts in this working copy. Resolve merge ".
        "conflicts before proceeding.\n\n".
        $working_copy_desc.
        "  Conflicts in working copy:\n".
        "    ".implode("\n    ", $conflicts)."\n");
    }

    $missing = $api->getMissingChanges();
    if ($missing) {
      throw new ArcanistUsageException(
        pht(
          "You have missing files in this working copy. Revert or formally ".
          "remove them (with `svn rm`) before proceeding.\n\n".
          "%s".
          "  Missing files in working copy:\n%s\n",
          $working_copy_desc,
          "    ".implode("\n    ", $missing)));
    }

    $unstaged = $api->getUnstagedChanges();
    if ($unstaged) {
      echo "You have unstaged changes in this working copy.\n\n".
        $working_copy_desc.
        "  Unstaged changes in working copy:\n".
        "    ".implode("\n    ", $unstaged)."\n";
      if ($this->askForAdd($unstaged)) {
        $api->addToCommit($unstaged);
        $must_commit += array_flip($unstaged);
      } else {
        $permit_autostash = $this->getConfigFromAnySource(
          'arc.autostash',
          false);
        if ($permit_autostash && $api->canStashChanges()) {
          echo "Stashing uncommitted changes. (You can restore them with ".
               "`git stash pop`.)\n";
          $api->stashChanges();
          $this->stashed = true;
        } else {
          throw new ArcanistUsageException(
            "Stage and commit (or revert) them before proceeding.");
        }
      }
    }

    $uncommitted = $api->getUncommittedChanges();
    foreach ($uncommitted as $key => $path) {
      if (array_key_exists($path, $must_commit)) {
        unset($uncommitted[$key]);
      }
    }
    if ($uncommitted) {
      echo "You have uncommitted changes in this working copy.\n\n".
        $working_copy_desc.
        "  Uncommitted changes in working copy:\n".
        "    ".implode("\n    ", $uncommitted)."\n";
      if ($this->askForAdd($uncommitted)) {
        $must_commit += array_flip($uncommitted);
      } else {
        throw new ArcanistUncommittedChangesException(
          "Commit (or revert) them before proceeding.");
      }
    }

    if ($must_commit) {
      if ($this->getShouldAmend()) {
        $commit = head($api->getLocalCommitInformation());
        $api->amendCommit($commit['message']);
      } else if ($api->supportsLocalCommits()) {
        $commit_message = phutil_console_prompt("Enter commit message:");
        if ($commit_message == '') {
          $commit_message = self::AUTO_COMMIT_TITLE;
        }
        $api->doCommit($commit_message);
      }
    }
  }

  private function getShouldAmend() {
    if ($this->shouldAmend === null) {
      $this->shouldAmend = $this->calculateShouldAmend();
    }
    return $this->shouldAmend;
  }

  private function calculateShouldAmend() {
    $api = $this->getRepositoryAPI();

    if ($this->isHistoryImmutable() || !$api->supportsAmend()) {
      return false;
    }

    $commits = $api->getLocalCommitInformation();
    if (!$commits) {
      return false;
    }

    $commit = reset($commits);
    $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
      $commit['message']);

    if ($message->getGitSVNBaseRevision()) {
      return false;
    }

    if ($api->getAuthor() != $commit['author']) {
      return false;
    }

    if ($message->getRevisionID() && $this->getArgument('create')) {
      return false;
    }

    // TODO: Check commits since tracking branch. If empty then return false.

    $repository = $this->loadProjectRepository();
    if ($repository) {
      $callsign = $repository['callsign'];
      $known_commits = $this->getConduit()->callMethodSynchronous(
        'diffusion.getcommits',
        array('commits' => array('r'.$callsign.$commit['commit'])));
      if (ifilter($known_commits, 'error', $negate = true)) {
        return false;
      }
    }

    if (!$message->getRevisionID()) {
      return true;
    }

    $in_working_copy = $api->loadWorkingCopyDifferentialRevisions(
      $this->getConduit(),
      array(
        'authors' => array($this->getUserPHID()),
        'status' => 'status-open',
      ));
    if ($in_working_copy) {
      return true;
    }

    return false;
  }

  private function askForAdd(array $files) {
    if ($this->commitMode == self::COMMIT_DISABLE) {
      return false;
    }
    if ($this->commitMode == self::COMMIT_ENABLE) {
      return true;
    }
    $prompt = $this->getAskForAddPrompt($files);
    return phutil_console_confirm($prompt);
  }

  private function getAskForAddPrompt(array $files) {
    if ($this->getShouldAmend()) {
      $prompt = pht(
        'Do you want to amend these files to the commit?',
        count($files));
    } else {
      $prompt = pht(
        'Do you want to add these files to the commit?',
        count($files));
    }
    return $prompt;
  }

  protected function loadDiffBundleFromConduit(
    ConduitClient $conduit,
    $diff_id) {

    return $this->loadBundleFromConduit(
      $conduit,
      array(
      'diff_id' => $diff_id,
    ));
  }

  protected function loadRevisionBundleFromConduit(
    ConduitClient $conduit,
    $revision_id) {

    return $this->loadBundleFromConduit(
      $conduit,
      array(
      'revision_id' => $revision_id,
    ));
  }

  private function loadBundleFromConduit(
    ConduitClient $conduit,
    $params) {

    $future = $conduit->callMethod('differential.getdiff', $params);
    $diff = $future->resolve();

    $changes = array();
    foreach ($diff['changes'] as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }
    $bundle = ArcanistBundle::newFromChanges($changes);
    $bundle->setConduit($conduit);
    // since the conduit method has changes, assume that these fields
    // could be unset
    $bundle->setProjectID(idx($diff, 'projectName'));
    $bundle->setBaseRevision(idx($diff, 'sourceControlBaseRevision'));
    $bundle->setRevisionID(idx($diff, 'revisionID'));
    $bundle->setAuthorName(idx($diff, 'authorName'));
    $bundle->setAuthorEmail(idx($diff, 'authorEmail'));
    return $bundle;
  }

  /**
   * Return a list of lines changed by the current diff, or ##null## if the
   * change list is meaningless (for example, because the path is a directory
   * or binary file).
   *
   * @param string      Path within the repository.
   * @param string      Change selection mode (see ArcanistDiffHunk).
   * @return list|null  List of changed line numbers, or null to indicate that
   *                    the path is not a line-oriented text file.
   */
  protected function getChangedLines($path, $mode) {
    $repository_api = $this->getRepositoryAPI();
    $full_path = $repository_api->getPath($path);
    if (is_dir($full_path)) {
      return null;
    }

    if (!file_exists($full_path)) {
      return null;
    }

    $change = $this->getChange($path);

    if ($change->getFileType() !== ArcanistDiffChangeType::FILE_TEXT) {
      return null;
    }

    $lines = $change->getChangedLines($mode);
    return array_keys($lines);
  }

  protected function getChange($path) {
    $repository_api = $this->getRepositoryAPI();

    // TODO: Very gross
    $is_git = ($repository_api instanceof ArcanistGitAPI);
    $is_hg = ($repository_api instanceof ArcanistMercurialAPI);
    $is_svn = ($repository_api instanceof ArcanistSubversionAPI);

    if ($is_svn) {
      // NOTE: In SVN, we don't currently support a "get all local changes"
      // operation, so special case it.
      if (empty($this->changeCache[$path])) {
        $diff = $repository_api->getRawDiffText($path);
        $parser = $this->newDiffParser();
        $changes = $parser->parseDiff($diff);
        if (count($changes) != 1) {
          throw new Exception("Expected exactly one change.");
        }
        $this->changeCache[$path] = reset($changes);
      }
    } else if ($is_git || $is_hg) {
      if (empty($this->changeCache)) {
        $changes = $repository_api->getAllLocalChanges();
        foreach ($changes as $change) {
          $this->changeCache[$change->getCurrentPath()] = $change;
        }
      }
    } else {
      throw new Exception("Missing VCS support.");
    }

    if (empty($this->changeCache[$path])) {
      if ($is_git || $is_hg) {
        // This can legitimately occur under git/hg if you make a change,
        // "git/hg commit" it, and then revert the change in the working copy
        // and run "arc lint".
        $change = new ArcanistDiffChange();
        $change->setCurrentPath($path);
        return $change;
      } else {
        throw new Exception(
          "Trying to get change for unchanged path '{$path}'!");
      }
    }

    return $this->changeCache[$path];
  }

  final public function willRunWorkflow() {
    $spec = $this->getCompleteArgumentSpecification();
    foreach ($this->arguments as $arg => $value) {
      if (empty($spec[$arg])) {
        continue;
      }
      $options = $spec[$arg];
      if (!empty($options['supports'])) {
        $system_name = $this->getRepositoryAPI()->getSourceControlSystemName();
        if (!in_array($system_name, $options['supports'])) {
          $extended_info = null;
          if (!empty($options['nosupport'][$system_name])) {
            $extended_info = ' '.$options['nosupport'][$system_name];
          }
          throw new ArcanistUsageException(
            "Option '--{$arg}' is not supported under {$system_name}.".
            $extended_info);
        }
      }
    }
  }

  protected function normalizeRevisionID($revision_id) {
    return preg_replace('/^D/i', '', $revision_id);
  }

  protected function shouldShellComplete() {
    return true;
  }

  protected function getShellCompletions(array $argv) {
    return array();
  }

  protected function getSupportedRevisionControlSystems() {
    return array('any');
  }

  protected function getPassthruArgumentsAsMap($command) {
    $map = array();
    foreach ($this->getCompleteArgumentSpecification() as $key => $spec) {
      if (!empty($spec['passthru'][$command])) {
        if (isset($this->arguments[$key])) {
          $map[$key] = $this->arguments[$key];
        }
      }
    }
    return $map;
  }

  protected function getPassthruArgumentsAsArgv($command) {
    $spec = $this->getCompleteArgumentSpecification();
    $map = $this->getPassthruArgumentsAsMap($command);
    $argv = array();
    foreach ($map as $key => $value) {
      $argv[] = '--'.$key;
      if (!empty($spec[$key]['param'])) {
        $argv[] = $value;
      }
    }
    return $argv;
  }

  /**
   * Write a message to stderr so that '--json' flags or stdout which is meant
   * to be piped somewhere aren't disrupted.
   *
   * @param string  Message to write to stderr.
   * @return void
   */
  protected function writeStatusMessage($msg) {
    fwrite(STDERR, $msg);
  }

  protected function isHistoryImmutable() {
    $repository_api = $this->getRepositoryAPI();

    $config = $this->getConfigFromAnySource('history.immutable');
    if ($config !== null) {
      return $config;
    }

    return $repository_api->isHistoryDefaultImmutable();
  }

  /**
   * Workflows like 'lint' and 'unit' operate on a list of working copy paths.
   * The user can either specify the paths explicitly ("a.js b.php"), or by
   * specfifying a revision ("--rev a3f10f1f") to select all paths modified
   * since that revision, or by omitting both and letting arc choose the
   * default relative revision.
   *
   * This method takes the user's selections and returns the paths that the
   * workflow should act upon.
   *
   * @param   list          List of explicitly provided paths.
   * @param   string|null   Revision name, if provided.
   * @param   mask          Mask of ArcanistRepositoryAPI flags to exclude.
   *                        Defaults to ArcanistRepositoryAPI::FLAG_UNTRACKED.
   * @return  list          List of paths the workflow should act on.
   */
  protected function selectPathsForWorkflow(
    array $paths,
    $rev,
    $omit_mask = null) {

    if ($omit_mask === null) {
      $omit_mask = ArcanistRepositoryAPI::FLAG_UNTRACKED;
    }

    if ($paths) {
      $working_copy = $this->getWorkingCopy();
      foreach ($paths as $key => $path) {
        $full_path = Filesystem::resolvePath($path);
        if (!Filesystem::pathExists($full_path)) {
          throw new ArcanistUsageException("Path '{$path}' does not exist!");
        }
        $relative_path = Filesystem::readablePath(
          $full_path,
          $working_copy->getProjectRoot());
        $paths[$key] = $relative_path;
      }
    } else {
      $repository_api = $this->getRepositoryAPI();

      if ($rev) {
        $this->parseBaseCommitArgument(array($rev));
      }

      $paths = $repository_api->getWorkingCopyStatus();
      foreach ($paths as $path => $flags) {
        if ($flags & $omit_mask) {
          unset($paths[$path]);
        }
      }
      $paths = array_keys($paths);
    }

    return array_values($paths);
  }

  protected function renderRevisionList(array $revisions) {
    $list = array();
    foreach ($revisions as $revision) {
      $list[] = '     - D'.$revision['id'].': '.$revision['title']."\n";
    }
    return implode('', $list);
  }


/* -(  Scratch Files  )------------------------------------------------------ */


  /**
   * Try to read a scratch file, if it exists and is readable.
   *
   * @param string Scratch file name.
   * @return mixed String for file contents, or false for failure.
   * @task scratch
   */
  protected function readScratchFile($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->readScratchFile($path);
  }


  /**
   * Try to read a scratch JSON file, if it exists and is readable.
   *
   * @param string Scratch file name.
   * @return array Empty array for failure.
   * @task scratch
   */
  protected function readScratchJSONFile($path) {
    $file = $this->readScratchFile($path);
    if (!$file) {
      return array();
    }
    return json_decode($file, true);
  }


  /**
   * Try to write a scratch file, if there's somewhere to put it and we can
   * write there.
   *
   * @param  string Scratch file name to write.
   * @param  string Data to write.
   * @return bool   True on success, false on failure.
   * @task scratch
   */
  protected function writeScratchFile($path, $data) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->writeScratchFile($path, $data);
  }


  /**
   * Try to write a scratch JSON file, if there's somewhere to put it and we can
   * write there.
   *
   * @param  string Scratch file name to write.
   * @param  array Data to write.
   * @return bool   True on success, false on failure.
   * @task scratch
   */
  protected function writeScratchJSONFile($path, array $data) {
    return $this->writeScratchFile($path, json_encode($data));
  }


  /**
   * Try to remove a scratch file.
   *
   * @param   string  Scratch file name to remove.
   * @return  bool    True if the file was removed successfully.
   * @task scratch
   */
  protected function removeScratchFile($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->removeScratchFile($path);
  }


  /**
   * Get a human-readable description of the scratch file location.
   *
   * @param string  Scratch file name.
   * @return mixed  String, or false on failure.
   * @task scratch
   */
  protected function getReadableScratchFilePath($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->getReadableScratchFilePath($path);
  }


  /**
   * Get the path to a scratch file, if possible.
   *
   * @param string  Scratch file name.
   * @return mixed  File path, or false on failure.
   * @task scratch
   */
  protected function getScratchFilePath($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->getScratchFilePath($path);
  }

  protected function getRepositoryEncoding() {
    $default = 'UTF-8';
    return nonempty(idx($this->getProjectInfo(), 'encoding'), $default);
  }

  protected function getProjectInfo() {
    if ($this->projectInfo === null) {
      $project_id = $this->getWorkingCopy()->getProjectID();
      if (!$project_id) {
        $this->projectInfo = array();
      } else {
        try {
          $this->projectInfo = $this->getConduit()->callMethodSynchronous(
            'arcanist.projectinfo',
            array(
              'name' => $project_id,
            ));
        } catch (ConduitClientException $ex) {
          if ($ex->getErrorCode() != 'ERR-BAD-ARCANIST-PROJECT') {
            throw $ex;
          }

          // TODO: Implement a proper query method that doesn't throw on
          // project not found. We just swallow this because some pathways,
          // like Git with uncommitted changes in a repository with a new
          // project ID, may attempt to access project information before
          // the project is created. See T2153.
          return array();
        }
      }
    }

    return $this->projectInfo;
  }

  protected function loadProjectRepository() {
    $project = $this->getProjectInfo();
    if (isset($project['repository'])) {
      return $project['repository'];
    }
    // NOTE: The rest of the code is here for backwards compatibility.

    $repository_phid = idx($project, 'repositoryPHID');
    if (!$repository_phid) {
      return array();
    }

    $repositories = $this->getConduit()->callMethodSynchronous(
      'repository.query',
      array());
    $repositories = ipull($repositories, null, 'phid');

    return idx($repositories, $repository_phid, array());
  }

  protected function newInteractiveEditor($text) {
    $editor = new PhutilInteractiveEditor($text);

    $preferred = $this->getConfigFromAnySource('editor');
    if ($preferred) {
      $editor->setPreferredEditor($preferred);
    }

    return $editor;
  }

  protected function newDiffParser() {
    $parser = new ArcanistDiffParser();
    if ($this->repositoryAPI) {
      $parser->setRepositoryAPI($this->getRepositoryAPI());
    }
    $parser->setWriteDiffOnFailure(true);
    return $parser;
  }

  protected function resolveCall(ConduitFuture $method, $timeout = null) {
    try {
      return $method->resolve($timeout);
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-CONDUIT-CALL') {
        echo phutil_console_wrap(
          "This feature requires a newer version of Phabricator. Please ".
          "update it using these instructions: ".
          "http://www.phabricator.com/docs/phabricator/article/".
          "Installation_Guide.html#updating-phabricator\n\n");
      }
      throw $ex;
    }
  }

  protected function dispatchEvent($type, array $data) {
    $data += array(
      'workflow' => $this,
    );

    $event = new PhutilEvent($type, $data);
    PhutilEventEngine::dispatchEvent($event);

    return $event;
  }

  public function parseBaseCommitArgument(array $argv) {
    if (!count($argv)) {
      return;
    }

    $api = $this->getRepositoryAPI();
    if (!$api->supportsCommitRanges()) {
      throw new ArcanistUsageException(
        "This version control system does not support commit ranges.");
    }

    if (count($argv) > 1) {
      throw new ArcanistUsageException(
        "Specify exactly one base commit. The end of the commit range is ".
        "always the working copy state.");
    }

    $api->setBaseCommit(head($argv));

    return $this;
  }

  protected function getRepositoryVersion() {
    if (!$this->repositoryVersion) {
      $api = $this->getRepositoryAPI();
      $commit = $api->getSourceControlBaseRevision();
      $versions = array('' => $commit);
      foreach ($api->getChangedFiles($commit) as $path => $mask) {
        $versions[$path] = (Filesystem::pathExists($path)
          ? md5_file($path)
          : '');
      }
      $this->repositoryVersion = md5(json_encode($versions));
    }
    return $this->repositoryVersion;
  }


/* -(  Phabricator Repositories  )------------------------------------------- */


  /**
   * Get the PHID of the Phabricator repository this working copy corresponds
   * to. Returns `null` if no repository can be identified.
   *
   * @return phid|null  Repository PHID, or null if no repository can be
   *                    identified.
   *
   * @task phabrep
   */
  protected function getRepositoryPHID() {
    return idx($this->getRepositoryInformation(), 'phid');
  }


  /**
   * Get the callsign of the Phabricator repository this working copy
   * corresponds to. Returns `null` if no repository can be identified.
   *
   * @return string|null  Repository callsign, or null if no repository can be
   *                      identified.
   *
   * @task phabrep
   */
  protected function getRepositoryCallsign() {
    return idx($this->getRepositoryInformation(), 'callsign');
  }


  /**
   * Get the URI of the Phabricator repository this working copy
   * corresponds to. Returns `null` if no repository can be identified.
   *
   * @return string|null  Repository URI, or null if no repository can be
   *                      identified.
   *
   * @task phabrep
   */
  protected function getRepositoryURI() {
    return idx($this->getRepositoryInformation(), 'uri');
  }


  /**
   * Get human-readable reasoning explaining how `arc` evaluated which
   * Phabricator repository corresponds to this working copy. Used by
   * `arc which` to explain the process to users.
   *
   * @return list<string> Human-readable explanation of the repository
   *                      association process.
   *
   * @task phabrep
   */
  protected function getRepositoryReasons() {
    $this->getRepositoryInformation();
    return $this->repositoryReasons;
  }


  /**
   * @task phabrep
   */
  private function getRepositoryInformation() {
    if ($this->repositoryInfo === null) {
      list($info, $reasons) = $this->loadRepositoryInformation();
      $this->repositoryInfo = nonempty($info, array());
      $this->repositoryReasons = $reasons;
    }

    return $this->repositoryInfo;
  }


  /**
   * @task phabrep
   */
  private function loadRepositoryInformation() {
    list($query, $reasons) = $this->getRepositoryQuery();
    if (!$query) {
      return array(null, $reasons);
    }

    try {
      $results = $this->getConduit()->callMethodSynchronous(
        'repository.query',
        $query);
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-CONDUIT-CALL') {
        $reasons[] = pht(
          'This version of Arcanist is more recent than the version of '.
          'Phabricator you are connecting to: the Phabricator install is '.
          'out of date and does not have support for identifying '.
          'repositories by callsign or URI. Update Phabricator to enable '.
          'these features.');
        return array(null, $reasons);
      }
      throw $ex;
    }

    $result = null;
    if (!$results) {
      $reasons[] = pht(
        'No repositories matched the query. Check that your configuration '.
        'is correct, or use "repository.callsign" to select a repository '.
        'explicitly.');
    } else if (count($results) > 1) {
      $reasons[] = pht(
        'Multiple repostories (%s) matched the query. You can use the '.
        '"repository.callsign" configuration to select the one you want.',
        implode(', ', ipull($results, 'callsign')));
    } else {
      $result = head($results);
      $reasons[] = pht('Found a unique matching repository.');
    }

    return array($result, $reasons);
  }


  /**
   * @task phabrep
   */
  private function getRepositoryQuery() {
    $reasons = array();

    $callsign = $this->getConfigFromAnySource('repository.callsign');
    if ($callsign) {
      $query = array(
        'callsigns' => array($callsign),
      );
      $reasons[] = pht(
        'Configuration value "repository.callsign" is set to "%s".',
        $callsign);
      return array($query, $reasons);
    } else {
      $reasons[] = pht(
        'Configuration value "repository.callsign" is empty.');
    }

    $project_info = $this->getProjectInfo();
    $project_name = $this->getWorkingCopy()->getProjectID();
    if ($this->getProjectInfo()) {
      if (!empty($project_info['repository']['callsign'])) {
        $callsign = $project_info['repository']['callsign'];
        $query = array(
          'callsigns' => array($callsign),
        );
        $reasons[] = pht(
          'Configuration value "project.name" is set to "%s"; this project '.
          'is associated with the "%s" repository.',
          $project_name,
          $callsign);
        return array($query, $reasons);
      } else {
        $reasons[] = pht(
          'Configuration value "project.name" is set to "%s", but this '.
          'project is not associated with a repository.',
          $project_name);
      }
    } else if (strlen($project_name)) {
      $reasons[] = pht(
        'Configuration value "project.name" is set to "%s", but that '.
        'project does not exist.',
        $project_name);
    } else {
      $reasons[] = pht(
        'Configuration value "project.name" is empty.');
    }

    $uuid = $this->getRepositoryAPI()->getRepositoryUUID();
    if ($uuid !== null) {
      $query = array(
        'uuids' => array($uuid),
      );
      $reasons[] = pht(
        'The UUID for this working copy is "%s".',
        $uuid);
      return array($query, $reasons);
    } else {
      $reasons[] = pht(
        'This repository has no VCS UUID (this is normal for git/hg).');
    }

    $remote_uri = $this->getRepositoryAPI()->getRemoteURI();
    if ($remote_uri !== null) {
      $query = array(
        'remoteURIs' => array($remote_uri),
      );
      $reasons[] = pht(
        'The remote URI for this working copy is "%s".',
        $remote_uri);
      return array($query, $reasons);
    } else {
      $reasons[] = pht(
        'Unable to determine the remote URI for this repository.');
    }

    return array(null, $reasons);
  }


  /**
   * Build a new lint engine for the current working copy.
   *
   * Optionally, you can pass an explicit engine class name to build an engine
   * of a particular class. Normally this is used to implement an `--engine`
   * flag from the CLI.
   *
   * @param string Optional explicit engine class name.
   * @return ArcanistLintEngine Constructed engine.
   */
  protected function newLintEngine($engine_class = null) {
    $working_copy = $this->getWorkingCopy();
    $config = $this->getConfigurationManager();

    if (!$engine_class) {
      $engine_class = $config->getConfigFromAnySource('lint.engine');
    }

    if (!$engine_class) {
      if (Filesystem::pathExists($working_copy->getProjectPath('.arclint'))) {
        $engine_class = 'ArcanistConfigurationDrivenLintEngine';
      }
    }

    if (!$engine_class) {
      throw new ArcanistNoEngineException(
        pht(
          "No lint engine is configured for this project. ".
          "Create an '.arclint' file, or configure an advanced engine ".
          "with 'lint.engine' in '.arcconfig'."));
    }

    $base_class = 'ArcanistLintEngine';
    if (!class_exists($engine_class) ||
        !is_subclass_of($engine_class, $base_class)) {
      throw new ArcanistUsageException(
        pht(
          'Configured lint engine "%s" is not a subclass of "%s", but must '.
          'be.',
          $engine_class,
          $base_class));
    }

    $engine = newv($engine_class, array())
      ->setWorkingCopy($working_copy)
      ->setConfigurationManager($config);

    return $engine;
  }

}
