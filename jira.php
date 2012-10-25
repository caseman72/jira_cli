#!/usr/bin/php
<?php
  # includes with full path
  $cwd = dirname(__FILE__);
  require_once("{$cwd}/__config.inc");
  require_once("{$cwd}/Mustache.php");

  function tsv ($a) {
    return str_replace('&nbsp;', '-', join("\t", array_values($a)));
  }

  class Jira
  {
    private $_user_agent = USER_AGENT;
    private $_default_id = JIRA_DEFAULT;
    private $_auth_basic = JIRA_AUTH;
    private $_jira_user = JIRA_USER;
    private $_jira_host = JIRA_HOST;
    private $_jira_list = JIRA_LIST;
    private $_jira_list_all = JIRA_LISTALL;
    private $_jira_phoenix = JIRA_PHOENIX;
    private $_jira_triage = JIRA_TRIAGE;

    // date convertions
    private $_month_to_index = array(
      'Jan' => 1,
      'Feb' => 2,
      'Mar' => 3,
      'Apr' => 4,
      'May' => 5,
      'Jun' => 6,
      'Jul' => 7,
      'Aug' => 8,
      'Sep' => 9,
      'Oct' => 10,
      'Nov' => 11,
      'Dec' => 12,
    );

    private function _tz_server() { return new DateTimeZone('GMT'); }
    private function _tz_local() { return new DateTimeZone(date_default_timezone_get()); }

    # full-path to local template
    private function _local($template)
    {
      return rtrim(dirname(__FILE__), '\/') . "/templates/{$template}.mustache";
    }

    # full-path to home template
    private function _home($template)
    {
      return rtrim(getenv('HOME'), '\/') . "/.jira/templates/{$template}.mustache";
    }

    # cookie file
    private function _cookie()
    {
      return rtrim(dirname(__FILE__), '\/') . '/.jira.cookies';
    }

    private $_colors = array(
      'BLACK'     => "\033[30m"
      , 'RED'     => "\033[31m"
      , 'GREEN'   => "\033[32m"
      , 'YELLOW'  => "\033[33m"
      , 'BLUE'    => "\033[34m"
      , 'MAGENTA' => "\033[35m"
      , 'CYAN'    => "\033[36m"
      , 'WHITE'   => "\033[37m"
      , 'UNDEF'   => "\033[38m"
      , 'RESET'   => "\033[39m"
    );

    # common search & replace - mainly for comments
    private $_search = array(
      "/[\r\n]+[ \t]*/"
      , "/[ \t]+/"
      , "/>[ ]+/"
      , "/[ ]+>/"
      , "/[ ]+</"
      , "/<[ ]+/"
      , "/[&]amp;/"
      , "/[&]#39;/"
      , "/[&]quot;/"
    );
    private $_replace = array(
      ''
      , ' '
      , '>'
      , '>'
      , '<'
      , '<'
      , '&'
      , "'"
      , '"'
    );

    private function _logon() {
      $file = $this->_cookie();

      $url = $this->_jira_host . '/secure/Dashboard.jspa';
      $data = `wget -q --keep-session-cookies --save-cookies='{$file}' --header='{$this->_auth_basic}' --header='X-Atlassian-Token: no-check' --user-agent='{$this->_user_agent}' '{$url}' -O -`;

      // TODO check for bad login ...
      // $data = preg_replace($this->_search, $this->_replace, $data);

      return true;
    }

    private function _wgetit($url, $post=false, $json=false)
    {
      $url = $this->_jira_host . $url;

      $file = $this->_cookie();
      $post = $post ? " --post-data " . escapeshellarg($post) : '';
      $json = $json ? ' --header="Content-Type: application/json"' : '';
      $data = `wget -q --load-cookies='{$file}' --header='{$this->_auth_basic}' --header='X-Atlassian-Token: no-check'{$json} --user-agent='{$this->_user_agent}'{$post} '{$url}' -O -`;

      return $json ? json_decode($data) : preg_replace($this->_search, $this->_replace, $data);
    }

    private function _getparams($html, $concatVersion = false)
    {
      if (preg_match("/^\w+[-][0-9]+$/", $html)) {
        $html = $this->_wgetit('/browse/' . strtoupper($html));
      }

      // find issue
      $issue = '';
      if (preg_match("/<a id=\"key-val\"[^>]*>([^<]+)<\/a>/", $html, $parts)) {
        $issue = $parts[1];
      }

      // find params
      $search = array(
        "/^.*?<a[^>]*href=\"\/secure\/AssignIssue\.jspa[?]([^\"]*)\"[^>]*>Assign To Me<\/a>.*$/",
        "/[&]assignee=[^&]*([&]?)/",
        "/^assignee=[^&]*[&]?/",
        "/[&]atl_token=[^&]*([&]?)/",
        "/^atl_token=[^&]*[&]?/"
      );
      $replace = array(
        "$1",
        "$1",  // removes assignee
        '',    // removes assignee
        "$1",  // removes atl_token
        ''     // removes atl_token
      );
      $params = preg_replace($search, $replace, $html);

      // find state
      if (preg_match("/<span[^>]*id=\"status-val\"[^>]*>(?:<img[^>]*>)(.*?)<\/span>/", $html, $state)) {
        $state = trim($state[1]);
      }
      else {
        $state = 'Comment';
      }

      // find fixforversion
      if (preg_match_all("/<a href=\"\/browse\/[^\/]+\/fixforversion\/([0-9]+)\"[^>]*>(.*?)<\/a>/", $html, $versions)) {
        $fixVersions = join($versions[1], '&fixVersions=');

        if ($concatVersion) {
          $params .= '&fixVersions='. $fixVersions;
        }
      }

      elseif ($concatVersion && $issue) {
        $project = preg_replace("/-.*$/", '', $issue); // get prefix
        $versions = $this->_versions($project);        // versions

        $sprint = false;
        $now = strtotime("now");
        foreach($versions as $date => $obj) {
          if ($date < 1300000000 || $date > $now) continue;
          $sprint = $obj['id'];
          break;
        }

        if ($sprint) {
          $params .= '&fixVersions='. $sprint;
        }
      }

      return array($state, $params, $issue);
    }

    private function _columns($values, $color=true)
    {
      $values = $values ? array_values($values) : array();
      $keys = $values ? join("\t", array_keys($values[0])) : '';

      $output = array_map("tsv", $values); // anonymouse fx ~ cheatin'

      if ($color) {
        $output = escapeshellarg("{$this->_colors['BLUE']}\n{$keys}{$this->_colors['RESET']}\n" . join("\n", $output));
        $output = `echo $output | column -ts'	' | sed ':a;N;$!ba;s/\\n//'`;
      }
      else {
        $output = escapeshellarg("{$keys}\n" . join("\n", $output));
        $output = `echo $output | column -ts'	'`;
      }

      return $output;
    }


    /**
      * users - must be an admin to view users
      *
      * HTML
      */
    function _users()
    {
      $search = array(
        "/<br[ \/]{0,2}>/"
        , "/^.*?<tbody>(.*?)<\/tbody>.*$/s"
        , "/<tr[^>]*>/"
        , "/<td><div><a[^>]*><span class=\"username\">(.*?)<\/span><\/a><\/div><\/td>/"
        , "/<td><span class=\"fn\">(.*?)<\/span><a[^>]*><span class=\"email\">(.*?)<\/span><\/a><\/td>/"
        , "/<td class=\"minNoWrap\">(.*?)<\/td>/"
        , "/<\/tr>/"
        , "/<td.*?<\/td>/"
        , "/Not recorded[ \t]*$/m"
        , "/<strong>Count:<\/strong>([0-9]+)<strong>Last:<\/strong>(.*)$/m"
      );
      $replace = array(
        ''
        , "$1"
        , ''
        , "$1\t"
        , "$1\t$2\t"
        , "$1\t"
        , "\n"
        , ''
        , "0\t"
        , "$1\t$2"
      );

      $html = $this->_wgetit('/secure/admin/user/UserBrowser.jspa', 'max=50');
      $html = trim(preg_replace($search, $replace, $html));

      $users = array();
      if (preg_match_all("/^([^\t]+)\t([^\t]+)\t([^\t]*)\t([0-9]+)\t(.*)$/m", $html, $matches, PREG_SET_ORDER)) {
        foreach($matches as $m) {
          list($dummy, $user, $name, $email, $count, $last) = $m;

          $last = str_replace('/', '-', trim($last));
          if ($last) {
            $dt = new DateTime($last, $this->_tz_local());
            $last = $dt->format('Y-m-d h:i A');
          }

          $key = strtolower(trim($user));
          $users[$key] = array('user' => $key, 'name' => trim($name), 'email' => $email, 'count' => $count, 'last' => $last);
        }
      }
      ksort($users);

      return $users;
    }
    function f_users($color=true)
    {
      print $this->_columns($this->_users(), $color);
    }


    /**
      * projects (with ids)
      *
      * HTML
      */
    function _projectIds()
    {
      $search = array(
        "/^.*?<tbody class=\"projects-list\">(.*?)<\/tbody>.*$/s"
        , "/<img [^>]*pid=([0-9]+)[^>]* \/>/"
        , "/<a href=\"\/browse\/[^\"]*\">([^<]+)<\/a>/"
        , "/<\/td><td><a class=\"user-hover\".*?<a href=\".*?<\/a>/"
        , "/<\/?t[rd]>/"
      );
      $replace = array(
        "$1"
        , "$1\t"
        , "$1\t"
        , "\n"
        , ''
      );

      $html = $this->_wgetit('/secure/BrowseProjects.jspa#all');
      $html = trim(preg_replace($search, $replace, $html));

      $projectIds = array();
      if (preg_match_all("/^([0-9]+)\t([^\t]+)\t(.+)$/m", $html, $matches, PREG_SET_ORDER)) {
        foreach($matches as $m) {
          list($dummy, $pid, $name, $key) = $m;
          $key = strtoupper(trim($key));
          $projectIds[$key] = array('key' => $key, 'name' => trim($name), 'pid' => trim($pid));
        }
      }
      ksort($projectIds);

      return $projectIds;
    }
    function f_projectids($color=true)
    {
      print $this->_columns($this->_projectIds(), $color);
    }


    /**
      * list - run a jql query from an id
      *
      * HTML
      */
    function _list($id, $jqlQuery=false)
    {
      $search = array(
        "/<br[ \/]{0,2}>/"
        , "/^.*?<tbody>/s"
        , "/<\/table>.*$/s"
        , "/<img [^>]*alt=\"([^\"]+)\"[^>]*>[^<]*/"
        , "/<time datetime=\"([^\"]+)\">[^<]+<\/time>/"
        , "/<\/?[^t][^>]*>/"
        , "/<td class=\"nav ([^\"]+)\">/"
        , "/<tr[^>]*>/"
        , "/^\t+|&nbsp;$/m"
        , "/\t+/"
        , "/Feature Enhancement/"
        , "/aggregatetimeoriginalestimate/"
      );
      $replace = array(
        ''
        , ''
        , ''
        , "$1"
        , "$1"
        , ''
        , "\t$1:"
        , "\n"
        , ''
        , "\t"
        , "Feature"
        , "Est"
      );

      $html = $jqlQuery
            ? $this->_wgetit("/sr/jira.issueviews:searchrequest-printable/temp/SearchRequest.html?jqlQuery={$jqlQuery}&tempMax=60")
            : $this->_wgetit("/sr/jira.issueviews:searchrequest-printable/{$id}/SearchRequest-{$id}.html?tempMax=60");

      $html = trim(preg_replace($search, $replace, $html));

      $issues = array();
      foreach(explode("\n", $html) as $row) {
        $issue = array();
        foreach(explode("\t", $row) as $col) {;
          if (preg_match("/^([^:]+?):(.+)$/", $col, $parts)) {
            $key = trim($parts[1]);
            $val = preg_replace($this->_search, $this->_replace, trim($parts[2]));

            # this is in local time because it's from HTML
            if (preg_match("/^[0-9]{4}[-][0-9]{2}[-][0-9]{2}T/", $val, $dummy)) {
              $dt = new DateTime($val, $this->_tz_local());
              $val = $dt->format('Y-m-d h:i A');
            }

            elseif ($key == 'summary') {
              if (strlen($val) > 63 ) {
                $val = substr($val, 0, 63) . ' ...';
              }
            }

            $issue[$key] = $val;
          }
        }
        if ($issue) {
          array_push($issues, $issue);
        }
      }

      return $issues;
    }
    function f_list($color=true)
    {
      print $this->_columns($this->_list($this->_jira_list), $color);
    }
    function f_listall($color=true)
    {
      print $this->_columns($this->_list($this->_jira_list_all), $color);
    }
    function f_phoenix($color=true)
    {
      print $this->_columns($this->_list($this->_jira_phoenix));
    }
    function f_triage($color=true)
    {
      print $this->_columns($this->_list($this->_jira_triage), $color);
    }
    function f_jql($jqlQuery, $color=true)
    {
      print $this->_columns($this->_list(false, urlencode($jqlQuery)), $color);
    }
    # TODO # ^^ maybe use a mustache template for the previous outputs

    function _versions($project)
    {
      $project = strtoupper($project);

      $json = $this->_wgetit("/rest/api/latest/project/{$project}/versions/", false, true);
      $json = is_array($json) ? $json : array();

      $versions = array();
      $index = 0;
      foreach($json as $v) {
        $name = trim($v->name);

        $release_date = $user_release_date = strtotime($v->releaseDate);
        if(preg_match("^/([0-9]{2})-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-([0-9]{2})/", $v->userReleaseDate, $parts)) {
          $user_release_date = mktime(0, 0, 0, $parts[3], $this->_month_to_index($parts[2]), $parts[1]);
        }
        $date_by_name = strtotime(preg_replace(array("/ .*$/", "/_/"), array('', '-'), $name));

        $date = max($release_date, $user_release_date, $date_by_name);

        $versions[$date ? $date : $index++] = array('id' => $v->id, 'name' => $name, 'date' => date('Y-m-d', $date));
      }
      krsort($versions);

      return $versions;
    }
    function f_versions($project)
    {
      print $this->_columns($this->_versions($project));
    }

    function _projects()
    {
      $json = $this->_wgetit('/rest/api/latest/project/', false, true);
      $json = is_array($json) ? $json : array();

      $projects = array();
      foreach($json as $p) {
        $key = strtoupper(trim($p->key));
        $projects[$key] = array('key' => $key, 'name' => trim($p->name));
      }
      ksort($projects);

      return $projects;
    }
    function f_projects($color=true)
    {
      print $this->_columns($this->_projects(), $color);
    }

    function f_issue($issue, $color=true, $wrap=80)
    {
      $json = $this->_wgetit('/rest/api/latest/issue/' . $issue, false, true);
      $json = $json->fields;

      $comments = array();
      # api doesn't redirect like html - so try url for new issue (and pray!)
      if (!$json) {
        list($state, $params, $newIssue) = $this->_getparams($issue, false);
        if ($newIssue && $newIssue != $issue) {
          return $this->f_issue($newIssue); // hopefully we don't get stuck in a loop!
        }
        die("404 - Not Found!\n");
      }

      $search = array(
        "/\r/"
        , "/\n*This message,.*?written consent of Advanced Energy Industries, Inc.\n*/s"
        , "/[ ]+$/m"
        , "/^\s\s*/s"
        , "/\s*\s$/s"
        , "/\n{2,}/s"
      );
      $replace = array(
        ''
        ,"\n\n"
        ,''
        ,''
        ,''
        ,"\n\n"
      );

      $view = array(
        'host' => $this->_jira_host
        , 'short_host' => preg_replace("/([^\.]+)\.[^.]+\.[^.]+$/", "$1", $this->_jira_host)
        , 'issue' => $issue
        , 'wrap' => $wrap
      );
      foreach($this->_colors as $key => $val) {
        $view[$key] = $color ? $val : '';
      }

      if ($json->comment) {
        foreach($json->comment->comments as $comment) {
          $dt = new DateTime($comment->created, $this->_tz_server());
          $dt->setTimezone($this->_tz_local());

          array_push($comments, array(
            'id' => preg_replace("/^.*\//", '', $comment->self)  # url for comment
            , 'author' => $comment->author->displayName
            , 'created' => $dt->format('Y-m-d h:i A')
            , 'body' => wordwrap(preg_replace($search, $replace, $comment->body), $view['wrap'])
          ));
        }
      }
      $view['comments'] = $comments;
      unset($json->comment);

      foreach(get_object_vars($json) as $key => $val) {
        if (isset($val->displayName)) {
          $view[$key] = $val->displayName;
        }
        elseif (isset($val->name)) {
          $view[$key] = $val->name;
        }
        elseif ($key == 'fixVersions') {
          $versions = array();
          foreach(($val ? $val : array()) as $v) {
            array_push($versions, $v->name);
          }
          $view[$key] = join(',', $versions);
        }
        elseif (isset($val)) {
          $value = $val;
          if (is_string($value)) {
            if (preg_match("/^[0-9]{4}[-][0-9]{2}[-][0-9]{2}T/", $value, $dummy)) {
              $dt = new DateTime($value, $this->_tz_server());
              $dt->setTimezone($this->_tz_local());
              $view[$key] = $dt->format('Y-m-d h:i A');
            }
            elseif ($key == 'description') {
              $view[$key] = wordwrap(preg_replace($search, $replace, $value), $view['wrap']);
            }
            else {
              $view[$key] = wordwrap($value, $view['wrap']);
            }
          }
          else {
            $view[$key] = $value;
          }
        }
      }

      # don't report updated if eq to created or resolved date
      if ($view['updated'] == $view['created'] || (isset($view['resolutiondate']) && $view['updated'] == $view['resolutiondate'])) {
        unset($view['updated']);
      }

      # don't display title and description if eq
      if ($view['summary'] == $view['description']) {
        unset($view['description']);
      }

      # don't show fixed
      if ($view['resolution'] == 'Fixed') {
        unset($view['resolution']);
      }

      # templates using mustache
      $home_template = $this->_home('issue');
      $template = file_exists($home_template)
                ? file_get_contents($home_template)
                : file_get_contents($this->_local('issue'));

      $m = new Mustache();
      print $m->render($template, $view);
    }
    function f_raw($issue)
    {
      return $this->f_issue($issue, false, 1024);
    }

    function f_fix($issue, $comment)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, true);
      if ($state == 'Open' || $state =='Reopened')
      {
        $params .= "&resolution=1&assignee={$this->_jira_user}&comment={$comment}&commentLevel=&viewIssueKey=";
        $this->_wgetit('/secure/CommentAssignIssue.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_wontfix($issue, $comment)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, true);
      if ($state == 'Open' || $state =='Reopened')
      {
        $params .= "&resolution=8&assignee={$this->_jira_user}&comment={$comment}&commentLevel=&viewIssueKey=";
        $this->_wgetit('/secure/CommentAssignIssue.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_duplicate($issue, $comment)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, true);
      if ($state == 'Open' || $state =='Reopened')
      {
        $params .= "&resolution=3&assignee={$this->_jira_user}&comment={$comment}&commentLevel=&viewIssueKey=";
        $this->_wgetit('/secure/CommentAssignIssue.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_notabug($issue)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, true);
      if ($state == 'Open' || $state =='Reopened')
      {
        $params .= "&resolution=6&assignee={$this->_jira_user}&comment={$comment}&commentLevel=&viewIssueKey=";
        $this->_wgetit('/secure/CommentAssignIssue.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_comment($issue, $comment)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, false);
      if ($state)
      {
        $params .= "&comment={$comment}&commentLevel=";
        $this->_wgetit('/secure/AddComment.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_delete($issue, $commentId)
    {
      $commentId = intval($commentId);
      if ($commentId)
      {
        list($state, $params, $dummy) = $this->_getparams($issue, false);
        if ($state)
        {
          $params .= "&commentId={$commentId}";
          $this->_wgetit('/secure/DeleteComment.jspa', $params);
        }
      }
      return $this->f_issue($issue);
    }

    function f_assign($issue, $user)
    {
      list($state, $params, $dummy) = $this->_getparams($issue, false);
      if ($state)
      {
        $user = urlencode($user); # TODO # verify

        $params .= "&assignee={$user}&comment=&commentLevel=";
        $this->_wgetit('/secure/AssignIssue.jspa', $params);
      }

      return $this->f_issue($issue);
    }

    function f_new($value, $comment)
    {
      $value = strtoupper($value);

      $projects = $this->_projectIds();
      $projectsRegex = join('|', array_keys($projects));

      $priorityIds = array(
        'BLOCK' => 1
        , 'BLOCKER' => 1
        , 'BLOCKING' => 1
        , 'CRITICAL' => 2
        , 'MAJOR' => 3
        , 'MINOR' => 4
        , 'TRIVIAL' => 5
      );
      $priorityRegex = join('|', array_keys($priorityIds));

      if (preg_match("/^($projectsRegex)-($priorityRegex)$/", $value, $parts)) {
        $pid = $projects[$parts[1]]['pid'];
        $priority = $priorityIds[$parts[2]];
      }
      elseif (preg_match("/^($projectsRegex)-([1-5])$/", $value, $parts)) {
        $pid = $projects[$parts[1]]['pid'];
        $priority = intval($parts[2]);
      }
      else {
        $pid = $this->_default_id;
        $priority = intval($value) ? intval($value) : 3;
      }

      $params = 'summary='     . $comment
              . '&reporter='   . $this->_jira_user
              . '&description='. $comment
              . '&assignee='   . $this->_jira_user
              . '&priority='   . $priority
              . '&pid='        . $pid
              . '&issuetype='  . '1';  // bug

      $html = $this->_wgetit('/secure/CreateIssueDetails.jspa', $params);
      list($state, $params, $issue) = $this->_getparams($html, false);

      return $this->f_issue($issue);
    }

    function f_help()
    {
      echo <<<EOS
NAME
    jira - command line version

SYNOPSIS
    jira [command] [issue/type] [comment]

DESCRIPTION
    Do I look like a tech writer?

COMMANDS
    list                 (default)
    listall
    triage
    project-id           (ex. HL-123)
    fix id comment
    comment id comment
    delete id commentId  (remove comment)
    wontfix id comment
    assign id user
    start id
    stop id
    reopen id comment
    new project-priority summary (priority: 1-blocker, 2-critical, 3-major, 4-minor, 5-trivial)
    jql 'JQL'            (ex. 'assignee = cmanion AND status in (Resolved)')
    help

EXAMPLES
    jira
    jira list
    jira HL-777
    jira new HL-3 "This is a new major issue created in HL"
    jira new HL-major "This is a new major issue created in HL"

EOS;
    }

    function __construct($argv)
    {
      // have to logon
      $this->_logon();

      $search = array(
        "/\\\\n/"
        , "/\\\\\\\/"
        , "/^[-]$/"
      );
      $replace = array(
        "\r\n"
        , "\r\n"
        , ''
      );

      $command = 'list';
      $issue = null;
      $comment = null;

      $argc = count($argv);
      switch($argc)
      {
        case 4:
          $comment = urlencode(preg_replace($search, $replace, $argv[3]));
        case 3:
          $issue   = strtoupper($argv[2]);
        case 2:
          $command = strtolower($argv[1]);
      }

      // jira hl-10
      if ($argc == 2) {
        $projectsRegex = join('|', array_keys($this->_projects()));
        if (preg_match("/^($projectsRegex)-/", strtoupper($command), $parts)) {
          $issue = strtoupper($command);
          $command = 'issue';
        }
      }

      // class method from command
      $command = "f_{$command}";

      switch($argc)
      {
        case 4:
          return $this->{$command}($issue, $comment);

        case 3:
          if (in_array($command, array('f_fix', 'f_wontfix', 'f_notabug', 'f_reopen', 'f_comment', 'f_new')))
          {
            $tmpfile = tempnam('/tmp', "{$this->_jira_user}_") . '.txt';

            // prepare comment file
            file_put_contents($tmpfile, "\n\n#- Please enter your comments\n#- (Lines starting with '#-' will not be included)\n#-\n");

            // run issue details (no color, no wrap) into file
            system('/usr/bin/php ' . __FILE__ . " raw {$issue} | sed 's/^/#- /;s/[ ]*$//' >> {$tmpfile}");

            if (getenv('OSTYPE') == 'cygwin')
            {
              // launch and wait
              system("psexec C:\\\\cygwin\\\\vim.bat /cygwin{$tmpfile} >& /dev/null");
            }
            else
            {
              $pid = pcntl_fork();
              if ($pid == -1)
              {
                die("Exiting - could not fork\n");
              }
              elseif ($pid)
              {
                // we are the parent
                pcntl_wait($status);

                // done with vim ...
                $comment = preg_replace("/[ ]+$/m", '', `grep -v '^#-' $tmpfile`);
                $comment = urlencode(preg_replace($search, $replace, $comment));

                if (!$comment) {
                  die("Exiting - no comment!\n");
                }

                return $this->{$command}($issue, $comment);
              }
              else
              {
                // we are the child exit when done...
                system("/usr/bin/vim $tmpfile > `tty`");
                exit;
              }
            }

            // done with vim ...
            $comment = trim(preg_replace("/[ ]+$/m", '', `grep -v '^#-' $tmpfile`));
            $comment = urlencode(preg_replace($search, $replace, $comment));

            if (!$comment) {
              die("Exiting - no comment!\n");
            }

            return $this->{$command}($issue, $comment);
          }
          else
          {
            return $this->{$command}($issue);
          }

        default:
          return $issue
            ? $this->{$command}($issue)
            : $this->{$command}();
      }
    }
  }

  $foo = new Jira($argv);

