<?php
/**
* Github model - provides functions for interacting with GitHub API
*/
if (file_exists('../config/projects_local.php')) {
  include_once('../config/projects_local.php');
} else {
  include_once('../config/projects.php');
}
include_once('../lib/restclient.php');
include_once('../lib/mysql_store.php');
include_once('../lib/json_store.php');
include_once('../lib/status_store.php');

class GithubClient extends RestClient
{
  private $users;
  private $statusDetailsKey;
  
  /*
   * function: GithubClient::processRequest
   * @param string $request - json payload
   * @desc: dispatch request to appropriate handler.
   */
  public function processRequest($request) {
    $event = $_SERVER['HTTP_X_GITHUB_EVENT'];
    switch ($event) {
      case 'pull_request':
        $this->processPullRequest($request);
        break;
      case 'status':
        $this->processStatus($request);
        break;
      default:
      $this->logger->error('received unhandled github event: ' . $event);
        break;
    }
  }
  /*
   * function: GithubClient::processPullRequest
   * @param string $request - json payload
   * @desc: retrieves all referenced commits and checks authors for CLA.
   *       also checks that Signed-off-by header is present and matches committer.
   */
  public function processPullRequest($request) {
    //get repo commits
    $json = json_decode(stripslashes($request));
    
    # fabricate ID for this transaction for logging purposes
    $pr_id = "PULL REQUEST:" . $json->repository->full_name . ":" . $json->number . " ";
    $this->logger->info($pr_id . 'NEW ' . $json->pull_request->html_url . ' ' . $json->action);

    //don't do evaluation if pr is closing
    if ($json->action == 'closed') { return; }

    $commits_url = $json->pull_request->url . '/commits';
    $statuses_url = $json->repository->statuses_url;
    $comments_url = $json->pull_request->comments_url;

    //get commits
    $commits = $this->get($commits_url);
    $this->logger->info($pr_id . 'commits url '.$commits_url . ' number of commits: ' . count($commits));
    # $this->logger->error('statuses url '.$statuses_url);
    # $this->logger->error('comments url '.$comments_url);

    //walk authors, testing CLA and Signed-off-by
    $this->users = array(
      'validCLA' => array(),
      'invalidCLA' => array(),
      'unknownCLA' => array(),
      'validSignedOff' => array(),
      'invalidSignedOff' => array(),
      'unknownSignedOff' => array()
    );
    
    $previous_committers = array();
    for ($i=0; $i < count($commits); $i++) { 
      //TODO: evaluate author as well or instead?
      $committer = $commits[$i]->commit->committer;
      $gh_committer = $commits[$i]->committer;
      if (!in_array($committer->email, $previous_committers)) {
        $previous_committers[] = $committer->email;
        $this->evaluateCLA($committer, $gh_committer);
        $this->evaluateSignature($commits[$i]->commit, $gh_committer);
      }
      //if there is no login, the user given in the git commit is not a valid github user
      $this->logger->info($pr_id . 'listed committer in commit: '.
        $commits[$i]->commit->committer->name .
        ' <'.$commits[$i]->commit->committer->email.'>');

      //Signed-off-by is found in the commit message
      $this->logger->info($pr_id . 'commit message: '.$commits[$i]->commit->message);      
    }

    //see if any problems were found, make suitable message
    $pullRequestState = $this->getPullRequestState();
    $pullRequestMessage = $this->composeStatusMessage();

    //get statuses (so we can provide history of 3rd party statuses)
    $status_history = $this->getCommitStatusHistory($statuses_url, end($commits));
    $this->users['StatusHistory'] = $status_history;
    
    //persist the status locally so it can be accessed at the github details url
    $this->storeStatus();
    
    //apply a new status to the pull request, targetting last commit.
    $result = $this->setCommitStatus($statuses_url, end($commits), $pullRequestState, $pullRequestMessage);
    
    //send mail to any configured addresses if the validation is unsuccessful
    if($pullRequestState == "failure") {
      $senderRecord = $this->getGithubUser($json->sender->login);
      $to = array();
      if ($senderRecord && isset($senderRecord->email)) {
        $to[] = $senderRecord->email;
      }
      $this->emailNotification($to, $pullRequestMessage, $json);
    }

    //add a comment to the pr with a link to an associated bug
    //bug 462471 - link bugs from bug numbers
    if ($json->action == 'opened') {
      $title = $json->pull_request->title;
      $organization = '';
      if ($json->repository && $json->repository->organization) {
        $organization = $json->repository->organization;
      }
      $pullRequestComment = $this->addBugLinkComment($comments_url, $title, $organization);
    }
    $this->callHooks('pull_request', $json);
    //TODO: close pull request?
  }
  /*
   * function: GithubClient::processStatus
   * @param string $request - json payload
   * @desc: determines if the status event was generated by this service.
   *        if not, it revalidates users, sets status and includes status 
   *        history in the details report.
   */
  public function processStatus($request) {
    $json = json_decode(stripslashes($request));
    $this->logger->error('processing repo status update with target_url:' . $json->target_url);
    if(stripos(WEBHOOK_SERVICE_URL, $json->target_url) === FALSE) {
      //third party must have set status
      //TODO: get the pull request and re-evaluate
      //TODO: set a new status and add third party status history to details
    }
    //do nothing, status is already set.
  }
  /*
   * function: GithubClient::emailNotification
   * @param array $to - email recipients
   * @param string $message - email body
   * @param object $json - pull request payload
   * @desc: sends email to the pull request originator with information about the failure
   */
  public function emailNotification($to, $message, $json) {
    $recipients = implode(',', $to);
    //ensure there is a recipient
    if ($recipients == '') {
      $recipients = ADMIN_EMAIL;
    }
    
    //TODO: move email strings to config
    
    $historyDetail = $this->users['StatusHistory'];
    $historyMessage = '';
    if (is_array($historyDetail) && count($historyDetail)) {
      $historyMessage = "\n\nExternal Service Status history: \n";
      $items = array();
      foreach($historyDetail as $item) {
        $items[] = "Description: ".$item['description']."\n" .
                   "State: ".$item['state']."\n" .
                   "Date: ".$item['created_at']."\n" .
                   "Details: ". $item['target_url'] ."\n";
      }
      $historyMessage .= implode("\n", $items);
    }
    
    $message = 'There was a problem validating pull request ' .
                $json->pull_request->url . "\r\n\n" .
                $message .
                $historyMessage;
    
    $subject = '[Eclipse-Github][Validation Error] '. $json->repository->full_name;
    $headers = 'From: noreply@eclipse.org' . "\r\n" .
               'Cc: ' . ADMIN_EMAIL . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    mail($recipients, $subject, $message, $headers);
  }
  /*
   * Function GithubClient::evaluateCLA
   * @param object committer - github user who made the commit
   * @desc evaluate CLA status against external service  
   */
  private function evaluateCLA($committer, $gh_committer) {
    $email = $committer->email;
    $gh_login = $gh_committer->login; // should perhaps use the numeric ID instead

    $eclipse_cla_status = $this->curl_get(CLA_SERVICE . $email);
    if ($eclipse_cla_status == '"TRUE"') {
      array_push($this->users['validCLA'], $email);
    } elseif ($eclipse_cla_status == '"FALSE"') {
      $eclipse_cla_status = $this->curl_get(CLA_SERVICE . $gh_login); // prefix "GITHUB:" ?
      if ($eclipse_cla_status == '"TRUE"') {
      	array_push($this->users['validCLA'], $gh_login);
      }
      else {
        array_push($this->users['invalidCLA'], $email);
      }
    } else {
      array_push($this->users['unknownCLA'], $email);
    }
  }
  
  /*
   * Function GithubClient::evaluateSignature
   * @param object commit
   * @desc evaluate signature match in Signed-off-by against committer
     @desc Signed-off-by is found in the commit message 
   */
  private function evaluateSignature($commit, $gh_committer) {
    $email = $commit->committer->email;
    $gh_login = $gh_committer->login;
    
    //look Signed-off-by pattern:
    $pattern = '/Signed-off-by:(.*)<(.*@.*)>$/m';
    //signature is only valid if it matches committer
    if (preg_match($pattern, $commit->message, $matches)) {
      if ($matches[2] == $email) {
        array_push($this->users['validSignedOff'], $email);
      } 
      elseif(trim($matches[1]) == $gh_login) {
        array_push($this->users['validSignedOff'], $gh_login);
      }
      else {
      	array_push($this->users['invalidSignedOff'], $gh_login);
      }
    } else {
      //no Signed-off-by at all
      array_push($this->users['unknownSignedOff'], $email);
    }
  }
  
  /*
   * Function GithubClient::getPullRequestState
   * @desc find the state for the entire message.
   * @return string expected by github status api
   */
  private function getPullRequestState() {
    if ((count($this->users['invalidSignedOff']) +
         count($this->users['unknownSignedOff']) +
         count($this->users['invalidCLA']) +
         count($this->users['unknownCLA']) == 0) &&
        (count($this->users['validCLA']) +
         count($this->users['validSignedOff']) > 0)) {
      return 'success';
    }
    return 'failure';
  }
  
  /*
   * Function GithubClient::storeStatus
   * @desc keep a record of the status to use in the details url on github
   */
  private function storeStatus() {
    $store = null;
    if (defined('MYSQL_DBNAME')) {
      $store = new MySQLStore();  
    } else {
      $store = new JSONStore();
    }
    $provider = new StatusStore($store);
  
    $this->statusDetailsKey = uniqid();
    return $provider->save($this->statusDetailsKey, $this->users); 
  }
  
  /*
   * Function GithubClient::composeStatusMessage
   * @desc build the status description including specific users and faults
   * @desc messages come from config/projects.php
   */
  private function composeStatusMessage() {
    global $messages;
    $parts = array();
    
    //list problems with corresponding users
    if (count($this->users['invalidCLA'])) {
      array_push($parts, $messages['badCLAs'] . implode(', ', $this->users['invalidCLA']));
    }
    if (count($this->users['unknownCLA'])) {
      array_push($parts, $messages['unknownUsers'] . implode(', ', $this->users['unknownCLA']));
    }
    if (count($this->users['invalidSignedOff'])) {
      array_push($parts, $messages['badSignatures'] . implode(', ', $this->users['invalidSignedOff']));
    }
    if (count($this->users['unknownSignedOff'])) {
      array_push($parts, $messages['badSignatures'] . implode(', ', $this->users['unknownSignedOff']));
    }
    //add a summary message
    if (count($parts)) {
      array_unshift($parts, $messages['failure']);
    } elseif (count($this->users['validCLA']) &&
              count($this->users['validSignedOff'])) {
      array_unshift($parts, $messages['success']);
    } else {
      array_unshift($parts, $messages['unknown']);
    }
    
    return implode("\n", $parts);
  }
  
  /*
   * Function GithubClient::setCommitStatus
   * @param object commit - target commit for status
   * @param string state - the state to apply [success, failure, pending]
   * @param string message - comments to explain the status
   * @desc POSTs the status message and appearance on github 
   */
  private function setCommitStatus($url, $commit, $state, $message) {
    $url = str_replace('{sha}', $commit->sha, $url);
    $this->logger->error('pull request status update url: '. $url);
    
    //create a details url for the status message
    $service_url_parts = explode('/', WEBHOOK_SERVICE_URL);
    array_pop($service_url_parts);
    array_push($service_url_parts, 'status_details.php?id=' . $this->statusDetailsKey);
    $details_url = implode('/', $service_url_parts);
    
    //create payload required for github status post
    //see http://developer.github.com/v3/repos/statuses/#create-a-status
    $payload = new stdClass();
    $payload->state = $state;
    $payload->target_url = $details_url;
    $payload->context = 'ip-validation';
    
    //TODO: handle github description limit of 140 chars gracefully
    if (strlen($message) < 140) {
      $payload->description = $message;
    } else {
      $payload->description = substr($message, 0, 137) . '...';
    }
    
    return $this->post($url, $payload);
  }
  
  /*
   * Function GithubClient::getCommitStatusHistory
   * @param object commit - commit to query for status
   * @desc GETs the status messages
   */
  private function getCommitStatusHistory($url, $commit) {
    $result = array();
    $url = str_replace('{sha}', $commit->sha, $url);
    $json = $this->get($url);
    
    for ($i=0; $i < count($json); $i++) {
      $status = $json[$i];

      //record only 3rd party statuses, which won't match our details url
      $service_url_parts = explode('/', WEBHOOK_SERVICE_URL);
      array_pop($service_url_parts);
      if (stripos($status->target_url, implode('/', $service_url_parts)) !== 0) {
        $result[] = array(
          "url" => $status->url,
          "created_at" => $status->created_at,
          "description" => $status->description,
          "state" => $status->state,
          "target_url" => $status->target_url
        );
      }
    }
    return $result;
  }
  
  /*
   * Function GithubClient::addBugLinkComment
   * @param string title - pr title to parse for bug reference
   * @param string organization - the bug tracker's organization 
   * @desc POSTs a comment to the pull request containing a link to
   *       a bug reference
   */
  private function addBugLinkComment($url, $title, $organization) {
    $orgName = ($organization == '')?'eclipse':$organization; 
    $this->logger->info('pull request comment url: '. $url);
    $this->logger->info("looking for bug reference in: $title");
    
    //match ~ Bug: xxx or [xxx]
    $re = "/[Bb]ug:?\s*#?(\d+)|\[(\d+)\]/";
    $matches = array();
    if (preg_match($re, $title, $matches) && count($matches) > 1) {
      //bug: match will be matches[1], [xxx] match will be matches[2]
      $nBug = count($matches) == 3 ? $matches[2]:$matches[1];
      $link = "https://bugs.$orgName.org/bugs/show_bug.cgi?id=$nBug";
      
      //create payload required for github comment post
      //see https://developer.github.com/v3/issues/comments/#create-a-comment
      $payload = new stdClass();
      $payload->body = "Issue tracker reference:\n". $link;
   
      return $this->post($url, $payload);
    };
    
    return false;
  }
  
  /*
   * Function GithubClient::callHooks
   * @param string event - the event type used to determine which hooks to call
   * @desc generically passes pr to scripts in the hooks directory based on action
   *       This is designed to be used for service specific actions.
   *       see bug: 462471
   */
  private function callHooks($event, $json) {
    $hookName = str_replace(array('/','\\','.'),'', $event.'_'.$json->action);
    $fileName = "./providers/hooks/$hookName.".php;
    $functionName = $hookName.'_hook';
    if (file_exists($fileName)) {
      include($fileName);
      if (is_callable($functionName)) {
        $this->logger->info("invoking custom hook function: $functionName");
        call_user_func($functionName, $json);
      }
    }
  }

  /*
   * Function GithubClient::getGithubUser
   * @param string login - github login to query
   * @desc GETs the complete user record
   */
  private function getGithubUser($login) {
    $url = implode('/', array(
      GITHUB_ENDPOINT_URL,
      'users',
      $login
    ));
    $resultObj = $this->get($url);
  
    if ($resultObj) {
      return $resultObj;
    }
    return NULL;
  }
  
}

?>
