#!/usr/bin/env php
<?php

class LogLevel
{
  const EMERGENCY = 1;
  const ALERT     = 2;
  const CRITICAL  = 3;
  const ERROR     = 4;
  const WARNING   = 5;
  const NOTICE    = 6;
  const INFO      = 7;
  const DEBUG     = 8;
}
$logLevelThreshold = LogLevel::WARNING;

function logmsg($level, $pattern /* ... */) {
  global $logLevelThreshold;
  if ($level > $logLevelThreshold)
    return;
  $console = PhutilConsole::getConsole();
  $argv = func_get_args();
  array_shift($argv);
  array_unshift($argv, "%s\n");
  call_user_func_array(array($console, 'writeOut'), $argv);
}

function debug($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::DEBUG);
  call_user_func_array('logmsg', $argv);
}

function warning($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::WARNING);
  call_user_func_array('logmsg', $argv);
}

function notice($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::NOTICE);
  call_user_func_array('logmsg', $argv);
}

function error($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::ERROR);
  call_user_func_array('logmsg', $argv);
  exit(1);
}


class PhillImporter
{
  protected $json = null;
  protected $jsonDir = null;
  protected $commitLevel = "global";
  protected $tasks = array();
  protected $projects = array();

  public function load_json($filename)
  {
    $this->jsonDir = realpath(dirname($filename));
    $data = file_get_contents($filename);
    $this->json = json_decode($data);
    if (json_last_error() != JSON_ERROR_NONE)
      throw new Exception("decoding json from '$filename' failed: " . json_last_error_msg());
    return $this->json;
  }

  public function set_transaction_level($val)
  {
    switch ($val) {
      case "global":
      case "item":
      case "rollback":
        $this->commitLevel = $val;
        break;
      default:
        throw new Exception("unknown transaction level '$val': valid ones are 'global', 'item' and 'rollback'.");
    }
  }

  protected function status_parse($status)
  {
    $map = ManiphestTaskStatus::getTaskStatusMap();
    if (!isset($map[$status]))
      error("status: '$status' is not valid");
    return $status;
  }

  protected function blurb_fixup_references($blurb)
  {
    if (!$blurb)
      return null;

    $patterns = array();
    $replacements = array();
    foreach ($this->tasks as $id=>$task) {
      $patterns[] = "/\\b$id\\b/m";
      $replacements[] = $task->getMonogram();
    }

    $blurb = preg_replace($patterns, $replacements, $blurb);
    return $blurb;
  }

  protected function user_lookup($address)
  {
    $user = id(new PhabricatorUser())->loadOneWithEmailAddress($address);
    if (!$user)
        throw new Exception("lookup of user <$address> failed");
    return $user;
  }

  protected function project_get_PHID($id)
  {
    return $this->projects[$id]->getPHID();
  }

  protected function project_get_PHIDs($ids)
  {
    $PHIDs = array();
    foreach($ids as $id)
      $PHIDs[] = $this->project_get_PHID($id);
    return array_fuse($PHIDs);
  }

  protected function project_lookup(PhabricatorUser $user, $phid, $name)
  {
    $project = id(new PhabricatorProject())->loadOneWhere('phid = %s', $phid);

    if (!$project) {
         $project = id(new PhabricatorProjectQuery())
                  ->setViewer($user)
                  ->withNames(array($name))
                  ->executeOne();
    }

    return $project;
  }

  protected function project_import($json)
  {
    debug("project: begin");
    $user = $this->user_lookup($json->creator);

    $name = $json->name;
    $PHID = "PHID-PROJ-ext-{$json->id}";
    $project = $this->project_lookup($user, $PHID, $name);
    if ($project) {
      $this->projects[$json->id] = $project;
      debug("project: {$PHID}: already imported '$json->id'");
      return;
    }

    PhabricatorPolicyFilter::requireCapability(
      $user,
      PhabricatorApplication::getByClass('PhabricatorProjectApplication'),
      ProjectCreateProjectsCapability::CAPABILITY);

    $slug = strtolower($json->id);
    $date = strtotime($json->creationDate);
    $project = PhabricatorProject::initializeNewProject($user)
      ->openTransaction()
      ->setPHID($PHID)
      ->setName(null)
      ->setDateCreated($date);

    notice("project: {$project->getPHID()}: created '$json->id'");

    $editor = id(new PhabricatorProjectTransactionEditor());

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorProjectTransaction::TYPE_NAME, $json->name, $date, null);

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorProjectTransaction::TYPE_SLUGS, array($slug, $json->id), $date, null);

    $description = property_exists($json, 'description') ? $json->description : '';
    $description = "$description\n\nImported from the $json->tracker instance at $json->url";

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorTransactions::TYPE_CUSTOMFIELD, $description, $date, null)
      ->setMetadataValue('customfield:key', 'std:project:internal:description')
      ->setOldValue(null);

    $members = $this->users_lookup_PHIDs($json->members);
    array_unshift($members, $user->getPHID());

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorTransactions::TYPE_EDGE, array('+' => array_fuse($members)), $date, null)
      ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);

    $this->transactions_apply($editor, $project, $user, $transactions);

    $project->saveTransaction();
    $this->projects[$json->id] = $project;

    notice("project: {$project->getPHID()}: imported '$json->name'");
  }

  protected function task_generate_PHID($id)
  {
    $PHIDType = ManiphestTaskPHIDType::TYPECONST;
    $PHID = "PHID-{$PHIDType}-ext-{$id}";
    return $PHID;
  }

  protected function task_lookup(PhabricatorUser $user, $phid)
  {
    $task = id(new ManiphestTask())->loadOneWhere('phid = %s', $phid);
    return $task;
  }

  protected function checkTransactionIsNew($task, $date)
  {
      $user = id(new PhabricatorUser())->loadOneWhere('phid = %s', $task->getAuthorPHID());

      $transactions = id(new ManiphestTransactionQuery())
          ->setViewer($user)
          ->withObjectPHIDs(mpull(array($task), 'getPHID'))
          ->needComments(true)
          ->execute();

      foreach ($transactions as $tr)
          if ($tr->getDateCreated() == $date) {
              return false;
          }

      return true;
  }

  protected function task_import($json)
  {
    $res = false;
    debug("task: begin");
    $user = $this->user_lookup($json->creator);

    $PHID =$this->task_generate_PHID($json->id);
    $task = $this->task_lookup($user, $PHID);
    if ($task) {
      $this->tasks[$json->id] = $task;
      debug("task: {$PHID}: already imported '$json->id'");
    }

    $date = strtotime($json->creationDate);
    $description = $this->blurb_fixup_references($json->description);
    $description = "$description\n\nImported from $json->url";
    $transactions = null;
    if (!$task) {
        notice("task: $PHID: creating '$json->title'");

        $task = ManiphestTask::initializeNewTask($user)
            ->openTransaction()
            ->setTitle(null)
            ->setPHID($PHID)
            ->setDescription($description)
            ->setDateCreated($date);

        $res = true;
        $transactions[] = $this->transaction_create('ManiphestTransaction', PhabricatorTransactions::TYPE_SUBSCRIBERS, array('=' => array($user->getPHID())), $date, null);
    } else {
        $task->openTransaction();
    }


    $editor = id(new ManiphestTransactionEditor());
    $title = $this->blurb_fixup_references($json->title);
    if ($user->getPHID() != $task->getOwnerPHID())
        $transactions[] = $this->transaction_create('ManiphestTransaction', ManiphestTransaction::TYPE_OWNER, $user->getPHID(), $date, null);
    if ($task->getDescription() != $description)
        $transactions[] = $this->transaction_create('ManiphestTransaction', ManiphestTransaction::TYPE_DESCRIPTION, $description, $date, null);
    if ($task->getTitle() != $title)
        $transactions[] = $this->transaction_create('ManiphestTransaction', ManiphestTransaction::TYPE_TITLE, $title, $date, null);

    notice("transaction: {$task->getPHID()}: initial title and subscribers");
    if ($transactions != null)
        $this->transactions_apply($editor, $task, $user, $transactions);

    $this->tasks[$json->id] = $task;

    $task->saveTransaction();
    notice("task: {$task->getPHID()}: imported '$json->title' as {$task->getMonogram()}");

    return $res;
  }

  protected function task_import_transactions($task, $json, $new)
  {
    $task->openTransaction();

    $count = count($json->transactions);
    $editor = id(new ManiphestTransactionEditor());
    foreach ($json->transactions as $idx=>$j) {
      $idx += 1; # show indexes starting from 1
      notice("task: {$task->getPHID()}: transaction begin $idx of $count");
      $user = $this->user_lookup($j->actor);
      $txn = $this->transaction_parse($task, $j, $new);
      if (!$txn)
          continue;
      $this->transactions_apply($editor, $task, $user, array($txn));
      notice("task: {$task->getPHID()}: transaction done");
    }

    $task->saveTransaction();
  }

  protected function transaction_create($class, $type, $value, $date, $comment)
  {
    $transaction = id(new $class())
      ->setTransactionType($type)
      ->setNewValue($value)
      ->setDateCreated($date);
    if ($comment)
      $transaction->attachComment(
        id(new ManiphestTransactionComment())->setContent($comment)
      );
    return $transaction;
  }

  protected function transaction_parse_type($type)
  {
    switch($type) {
      case "projects":    return PhabricatorTransactions::TYPE_EDGE;
      case "title":       return ManiphestTransaction::TYPE_TITLE;
      case "description": return ManiphestTransaction::TYPE_DESCRIPTION;
      case "priority":    return ManiphestTransaction::TYPE_PRIORITY;
      case "owner":       return ManiphestTransaction::TYPE_OWNER;
      case "attachment":  return PhabricatorTransactions::TYPE_COMMENT;
      case "comment":     return PhabricatorTransactions::TYPE_COMMENT;
      case "status":      return ManiphestTransaction::TYPE_STATUS;
      case "subscribers": return PhabricatorTransactions::TYPE_SUBSCRIBERS;
    }
    error("transaction: unknown type '$type'.");
  }

  protected function users_lookup_PHIDs($users)
  {
    $PHIDs = array();
    foreach($users as $user)
      $PHIDs[] = $this->user_lookup($user)->getPHID();
    return $PHIDs;
  }

  protected function transaction_parse(ManiphestTask $task, $json, $new_task)
  {
    $date = strtotime($json->date);
    $type = $this->transaction_parse_type($json->type);
    $value = property_exists($json, 'value') ? $json->value : '';
    $comment = property_exists($json, 'comment') ? $json->comment : '';
    $comment = $this->blurb_fixup_references($comment);
    $metadata = null;
    $check_new = false;

    switch($json->type) {
      case "owner":
        $value = $this->user_lookup($value)->getPHID();
        if (!$new_task) {
            return null;
        }
        break;
      case "description":
        $desc = explode("\n", trim($task->getDescription()));
        $tagline = end($desc);
        $value = $this->blurb_fixup_references($value);
        $value = "$value\n\n$tagline";

        $value = "$value\n\nImported from $json->url";
        if (!$new_task) {
            if ($task->getDescription() == $value)
                return null;
        }
        break;
      case "priority":
          if (!$new_task) {
              if ($task->getPriority() == $value)
                  return null;
          }
        break;
      case "attachment":
        $monogram = $this->file_ensure($task, $value)->getMonogram();
        $comment = "Uploaded {{$monogram}}\n\n$comment";
        $check_new = true;
        break;
      case "status":
        $value = $this->status_parse($value);
          if (!$new_task) {
              if ($task->getStatus() == $value)
                  return null;
          }
        break;
        break;
      case "projects":
          if ($new_task) {
              // FIXME try to be smart here :)
              return null;
          }

        if (property_exists($value, '+'))
          $t['+'] = $this->project_get_PHIDs($value->{'+'});
        if (property_exists($value, '-'))
          $t['-'] = $this->project_get_PHIDs($value->{'-'});
        if (property_exists($value, '='))
          $t['='] = $this->project_get_PHIDs($value->{'='});
        $value = $t;
        $metadata = array('edge:type', PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
        break;
      case "subscribers":
        if (property_exists($value, '+'))
          $t['+'] = $this->users_lookup_PHIDs($value->{'+'});
        if (property_exists($value, '-'))
          $t['-'] = $this->users_lookup_PHIDs($value->{'-'});
        if (property_exists($value, '='))
          $t['='] = $this->users_lookup_PHIDs($value->{'-'});
        $value = $t;
        break;
      case "comment":
        $check_new = true;
        break;
    }

    if ($check_new && !$this->checkTransactionIsNew($task, $date)) {
        return null;
    }


    $transaction = $this->transaction_create('ManiphestTransaction', $type, $value, $date, $comment);
    if ($metadata)
      $transaction->setMetadataValue($metadata[0], $metadata[1]);

    notice("transaction: {$task->getPHID()}: parsed '$json->type'");
    return $transaction;
  }

  protected function transactions_apply(PhabricatorApplicationTransactionEditor $editor, PhabricatorLiskDAO $subject, PhabricatorUser $user, array $transactions)
  {
    $editor->setActor($user)
      ->setContentSource(PhabricatorContentSource::newConsoleSource())
      ->setContinueOnMissingFields(true)
      ->setContinueOnNoEffect(true)
      ->applyTransactions($subject, $transactions);
    $count = count($transactions);
    notice("transaction: {$subject->getPHID()}: applied $count transactions as {$user->getUsername()}");
  }

  protected function file_ensure(ManiphestTask $task, $json)
  {
    $user = $this->user_lookup($json->author);

    # interpret paths as relative to the directory of the JSON file and make sure they stay inside it
    $path = realpath($this->jsonDir . "/" .$json->data);
    if (substr($path, 0, strlen($this->jsonDir)) !== $this->jsonDir)
      error("attachment: path '{$json->data}' falls outside of '{$this->jsonDir}'.");

    $contents = file_get_contents($path);
    $file = PhabricatorFile::newFromFileData(
      $contents,
      array(
        'authorPHID' => $user->getPHID(),
        'name' => $json->name,
        'isExplicitUpload' => true,
        'mime-type' => $json->mimetype
      ));

    return $file;
  }

  protected function process_commit_level_prepare()
  {
    $connections = [];
    if (in_array ($this->commitLevel, ["global", "rollback"])) {
      $connections[] = id(new PhabricatorProject())->establishConnection('w');
      $connections[] = id(new ManiphestTask())->establishConnection('w');

      debug("process: prepare commit level '$this->commitLevel'");
      foreach($connections as $conn)
        $conn->openTransaction();
    }
    return $connections;
  }

  protected function process_commit_level_end($connections)
  {
    if ($this->commitLevel == "global") {
      debug("process: commit");
      foreach($connections as $conn)
        $conn->saveTransaction();
    }
    elseif ($this->commitLevel == "rollback") {
      debug("process: rollback");
      foreach($connections as $conn)
        $conn->killTransaction();
    }
  }

  public function process()
  {
    debug("process: begin");
    $connections = $this->process_commit_level_prepare();

    # make sure we process things in-order to make references work
    $projects = ppull($this->json->projects, null, 'id');
    $tasks = ppull($this->json->tasks, null, 'id');
    ksort($projects, SORT_NATURAL);
    ksort($tasks, SORT_NATURAL);

    foreach($projects as $id=>$project)
      $this->project_import($project);

    $imported = array();
    $updates = array();
    foreach($tasks as $id=>$task){
        if ($this->task_import($task))
            $imported[$id] = $task;
        else
            $updates[$id] = $task;
    }

    # import task transactions as a separate step to be able to update the
    # issue references in descriptions and comments
    foreach($imported as $id=>$task)
      $this->task_import_transactions($this->tasks[$id], $task, true);

    foreach($updates as $id=>$task)
      $this->task_import_transactions($this->tasks[$id], $task, false);

    $this->process_commit_level_end($connections);
    debug("process: end");
  }
}


$root = getenv("PHABRICATOR_ROOT");
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);

$args->setTagline(pht('Import projects and tasks'));
$args->setSynopsis(<<<EOHELP
**phill** __JSONFILE__ [__options__]
  Fill Phabricator/Maniphest with tasks that everybody can just ignore.
EOHELP
);
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'    => 'input',
      'short'   => 'i',
      'param'   => 'FILE',
      'help'    => pht("The JSON-encoded file with the data to import")
    ),
    array(
      'name'    => 'transaction-level',
      'short'   => 't',
      'param'   => 'LEVEL',
      'default' => 'global',
      'help'    => pht("How much transactional the import should be: global, item or rollback (to test the import)")
    ),
    array(
      'name'    => 'verbose',
      'short'   => 'v',
      'param'   => 'LEVEL',
      'default' => '0',
      'help'    => pht("Enable verbose output, use LEVEL 2 to enable debug output")
    )
  )
);

switch($args->getArg('verbose')) {
  case 0:
    break;
  case 1:
    $logLevelThreshold = LogLevel::NOTICE;
    break;
  default:
    $logLevelThreshold = LogLevel::DEBUG;
    break;
}

$jsonfile = $args->getArg('input');
if (!$jsonfile)
  error("No file to import specified on the command line.");

$importer = new PhillImporter();
if (!$importer->load_json($jsonfile))
  error("Unable to load JSON data from '$jsonfile'.");

$importer->set_transaction_level($args->getArg('transaction-level'));
$importer->process();
