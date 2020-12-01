<?php
include 'config.php';
// config.php content:
// <?php
// define('BOT_TOKEN', 'XXXXX');


DEFINE('BOT_URL',$_SERVER["SCRIPT_URI"]);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('BOT_NAME', 'hellogithub_bot');

setlocale(LC_TIME, "de_DE");

function apiRequestWebhook($method, $parameters)
{
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $parameters["method"] = $method;

    header("Content-Type: application/json");
    echo json_encode($parameters);
    return true;
}

function exec_curl_request($handle)
{
    $response = curl_exec($handle);

    if ($response === false) {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        error_log("Curl returned error $errno: $error\n");
        curl_close($handle);
        return false;
    }

    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);

    if ($http_code >= 500) {
        // do not wat to DDOS server if something goes wrong
        sleep(10);
        return false;
    } else if ($http_code != 200) {
        $response = json_decode($response, true);
        error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
        if ($http_code == 401) {
            throw new Exception('Invalid access token provided');
        }
        return false;
    } else {
        $response = json_decode($response, true);
        if (isset($response['description'])) {
            error_log("Request was successful: {$response['description']}\n");
        }
        $response = $response['result'];
    }

    return $response;
}

function apiRequest($method, $parameters)
{
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }
    $parameters['parse_mode'] = "html";
    foreach ($parameters as $key => &$val) {
        // encoding to JSON array parameters, for example reply_markup
        if (!is_numeric($val) && !is_string($val)) {
            $val = json_encode($val);
        }
    }
    $url = API_URL . $method . '?' . http_build_query($parameters);

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);

    return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters)
{
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $parameters["method"] = $method;

    $handle = curl_init(API_URL);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

    return exec_curl_request($handle);
}

//  \u{1F64B}

function processMessage($message)
{
    // process incoming message
    $message_id = $message['message_id'];
    $chat_id = $message['chat']['id'];
    if (isset($message['text'])) {
        // incoming text message
        $text = $message['text'];

        if (strpos($text, "/start") === 0) {
            $welcome_msg = "Hi 🙋!\nGo to  <i>GitHub >> my-account/my-repo >> Settings </i>  and add this URL as new Webhook (application/JSON): \n\n";
            $compose_url = BOT_URL . '?chatid=' . $chat_id;
            apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => $welcome_msg . "<code>" . $compose_url . "</code>", 'reply_markup' => array('remove_keyboard' => true)));
        } else if ($text === "Hello" || $text === "Hi") {
            apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Nice to meet you'));
        } else if (strpos($text, "/stop") === 0) {
            // stop now
        }
    } else {
        // apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'I only do text messages'));
    }
}



///////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function getFingerprint($sshPublicKey, $hashAlgorithm = 'sha256')
{
    if (substr($sshPublicKey, 0, 8) != 'ssh-rsa ') {
        return;
    }
    $content = explode(' ', $sshPublicKey, 3);
    switch ($hashAlgorithm) {
        case 'md5':
            $fingerprint = join(':', str_split(md5(base64_decode($content[1])), 2));
            break;
        case 'sha256':
            $fingerprint = base64_encode(hash('sha256', base64_decode($content[1]), true));
            break;
        default:
            break;
    }
    return $fingerprint;
}

function makeName($update) {
    $u_name = $update["head_commit"]["author"]["name"];

    if ($u_name && $update["head_commit"]["author"]["username"]) {
        $u_name .= " (".$update["head_commit"]["author"]["username"].")";
    } 
    if (!$u_name) {
        $u_name = $update["head_commit"]["author"]["username"];
    }
    if (!$u_name) {
        $u_name = $update["sender"]["login"];    
    }
    return $u_name;
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function gEventPush($update)
{
    if (count($update["commits"]) == 0) {
        return;
    }
    $r = explode("/", $update["ref"]);
    $branch = array_pop($r);
    $ref = array_pop($r);

    $u_name = makeName($update);

    $msgText = "🔶 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"];
    // if ($update["repository"]["private"]) {
    //     $msgText .= " 🔒";
    // }
    $msgText .= "</a> on <code>" . $ref . "/" . $branch . " </code>\n";
    $msgText .= "<b>" . $u_name . "</b> pushed a total of <b>" . count($update["commits"]) . "</b> commits:";
    $it = 0;
    foreach ($update["commits"] as $commit) {
        $hash = substr($commit["id"], 0, 7);
        $msg = explode("\n", $commit["message"])[0];

        $datetime = date_format(date_create($commit["timestamp"]), "l, H:i:s");

        $msgText .= "\n\n<a href='" . $commit["url"] . "' >" . $hash . "</a> from " . $datetime . " ";
        $msgText .= "\n<b>" . $msg . "</b>";
        $msgText .= "\nModified: <b>" . count($commit["modified"]) . "</b> | ";
        $msgText .= "New: <b>" . count($commit["added"]) . "</b> | ";
        $msgText .= "Removed: <b>" . count($commit["removed"]) . "</b>";
        if ($it++ > 6) {
            $msgText .= "\n\n...";
            break;
        }
    }

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventPing($update)
{
    $msgText = "Ping 🔔!\n";
    $msgText .= "Your repository " . $update["repository"]["html_url"] . " has been connected.\n";
    $msgText .= "\nWise Octocat says: \n   <b>" . $update["zen"] . "</b>";
    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventIssues($update)
{
    $reponame = "in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a>";
    $msgText = "";

    if ($update["action"] == "opened") {
        $msgText .= "🟩 " . $reponame;
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> opened <a href='" . $update["issue"]["html_url"] . "'>issue #" . $update["issue"]["number"] . "</a>: ";
        $msgText .= "\n  " . $update["issue"]["title"] . "";
    } elseif ($update["action"] == "closed") {
        $msgText .= "🟥 " . $reponame;
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> closed <a href='" . $update["issue"]["html_url"] . "'>issue #" . $update["issue"]["number"] . "</a>: ";
        $msgText .= "\n  " . $update["issue"]["title"] . "";
    } elseif ($update["action"] == "reopened") {
        $msgText .= "🟨 " . $reponame;
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> re-opened <a href='" . $update["issue"]["html_url"] . "'>issue #" . $update["issue"]["number"] . "</a>: ";
        $msgText .= "\n  " . $update["issue"]["title"] . "";
    }

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventMember($update)
{
    $msgText = "🧑‍💻 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a>";
    if ($update["action"] == "added") {
        $msgText .= "\n<b>" . $update["member"]["login"] . "</b> has been added as a collaborator!";
    } elseif ($update["action"] == "removed") {
        $msgText .= "\n<b>" . $update["member"]["login"] . "</b> has been removed from this repository";
    } else {
        return;
    }
    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventDeployKey($update)
{
    $msgText = "🔑 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a>";
    if ($update["action"] == "created") {
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> added a new SSH key:";
        $msgText .= " <b>" . $update["key"]["title"] . "</b> \n<code>SHA256:" . getFingerprint($update["key"]["key"]) . "</code>";

        if ($update["key"]["read_only"] == true) {
            $msgText .= "\nPermissions: <b>Read Only</b>";
        } else {
            $msgText .= "\nPermissions: <b>Read/Write</b>";
        }
    } else if ($update["action"] == "deleted") {
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> deleted a SSH key:";
        $msgText .= " <b>" . $update["key"]["title"] . "</b>";
    }

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventPullRequest($update)
{
    $msgText = "🔷 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a> ";

    if ($update["action"] == "opened" || $update["action"] == "reopened") {
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> opened <a href='" . $update["pull_request"]["html_url"] . "'>pull request #" . $update["pull_request"]["number"] . "</a>: ";
        $msgText .= "\n<b>" . $update["pull_request"]["title"] . "</b>";
        $msgText .= "\n<code>[" . $update["pull_request"]["base"]["repo"]["full_name"] . "] " . $update["pull_request"]["base"]["ref"];
        $msgText .= " ← [" . $update["pull_request"]["head"]["repo"]["full_name"] . "] " . $update["pull_request"]["head"]["ref"] . "</code>";
    } elseif ($update["action"] == "closed") {
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> closed <a href='" . $update["pull_request"]["html_url"] . "'>pull request #" . $update["pull_request"]["number"] . "</a>: ";
        $msgText .= "\n<b>" . $update["pull_request"]["title"] . "</b>";
        if ($update["action"]["merged_at"] != "") {
            $msgText .= "\n\n<b>#" . $update["pull_request"]["number"] . " was merged.</b>";
        }
    }  elseif ($update["action"] == "synchronize") {
        $msgText .= "\n<b>" . $update["sender"]["login"] . "</b> triggered an update on <a href='" . $update["pull_request"]["html_url"] . "'>pull request #" . $update["pull_request"]["number"] . "</a> ";
        // $msgText .= "\n<b>" . $update["pull_request"]["title"] . "</b>";
    }  else {
        return;
    }

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventCreateRef($update) {
    $msgText = "🔶 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a> ";
    $msgText .= "\n<b>" . makeName($update) . "</b> created ".$update["ref_type"]." <code>".$update["ref"]."</code>";

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventDeleteRef($update){
    $msgText = "🔶 in <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a> ";
    $msgText .= "\n<b>" . makeName($update) . "</b> deleted ".$update["ref_type"]." <code>".$update["ref"]."</code>";

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

function gEventPublic($update) {
    $msgText = "🎉 <a href='" . $update["repository"]["html_url"] . "' >" . $update["repository"]["full_name"] . "</a> ";
    $msgText .= "\nis now publicly available!";

    apiRequest("sendMessage", array('chat_id' => $_GET["chatid"], "text" => $msgText));
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
$content = file_get_contents("php://input");
$update = json_decode($content, true);


if ($_GET["token"] == BOT_TOKEN) {        // when gets called by telegram bot api
    if (isset($update["message"])) {
        processMessage($update["message"]);
    } elseif (isset($_GET["webhook"])) {
        $tgUri = API_URL . "setwebhook?url=" . BOT_URL . "?token=" . BOT_TOKEN;
        print($tgUri);
        $handle = curl_init($tgUri);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);
        exec_curl_request($handle);
    }

    exit;
}

if (!$update) { //abort if request non json
    echo "<p><a href='http://t.me/" . BOT_NAME . "'>http://t.me/" . BOT_NAME . "</a></p>";
    exit;
}

if ($_GET["chatid"]) {

    $headerGitHubEvent = $_SERVER['HTTP_X_GITHUB_EVENT'];
    // $filter=$_GET["branch"];

    if ($headerGitHubEvent == "push") {
        gEventPush($update);
    } elseif ($headerGitHubEvent == "ping") {
        gEventPing($update);
    } elseif ($headerGitHubEvent == "issues") {
        gEventIssues($update);
    } elseif ($headerGitHubEvent == "member") {
        gEventMember($update);
    } elseif ($headerGitHubEvent == "deploy_key") {
        gEventDeployKey($update);
    } elseif ($headerGitHubEvent == "pull_request") {
        gEventPullRequest($update);
    } elseif ($headerGitHubEvent == "delete") {
        gEventDeleteRef($update);
    } elseif ($headerGitHubEvent == "create") {
        gEventCreateRef($update);
    } elseif ($headerGitHubEvent == "public") {
        gEventPublic($update);
    }
} else {
   
}
