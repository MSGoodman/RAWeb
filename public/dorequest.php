<?php

use App\Community\Enums\ActivityType;
use App\Platform\Enums\AchievementFlag;
use App\Platform\Enums\UnlockMode;
use App\Platform\Events\PlayerSessionHeartbeat;
use App\Platform\Jobs\UnlockPlayerAchievementJob;
use App\Platform\Models\Achievement;
use App\Platform\Models\Game;
use App\Site\Enums\Permissions;
use App\Site\Models\User;
use App\Support\Media\FilenameIterator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * @usage
 * dorequest.php?r=addfriend&<params> (Web)
 * dorequest.php?r=addfriend&u=user&t=token&<params> (From App)
 */
$response = ['Success' => true];

/**
 * AVOID A G O C - these are now strongly typed as INT!
 * Global RESERVED vars:
 */
$requestType = request()->input('r');
$username = request()->input('u');
$token = request()->input('t');
$achievementID = (int) request()->input('a', 0);  // Keep in mind, this will overwrite anything given outside these params!!
$gameID = (int) request()->input('g', 0);
$offset = (int) request()->input('o', 0);
$count = (int) request()->input('c', 10);

$validLogin = false;
$permissions = null;
if (!empty($token)) {
    $validLogin = authenticateFromAppToken($username, $token, $permissions);
}

/** @var ?User $user */
$user = request()->user('connect-token');

if (!function_exists('DoRequestError')) {
    function DoRequestError(string $error, ?int $status = 200, ?string $code = null): JsonResponse
    {
        $response = [
            'Success' => false,
            'Error' => $error,
        ];

        if ($code !== null) {
            $response['Code'] = $code;
        }

        if ($status !== 200) {
            $response['Status'] = $status;

            if ($status === 401) {
                return response()->json($response, $status)->header('WWW-Authenticate', 'Bearer');
            }

            return response()->json($response, $status);
        }

        return response()->json($response);
    }
}

/**
 * RAIntegration implementation
 * https://github.com/RetroAchievements/RAIntegration/blob/master/src/api/impl/ConnectedServer.cpp
 */

/**
 * Early exit if we need a valid login
 */
$credentialsOK = match ($requestType) {
    /*
     * Registration required and user=local
     */
    "achievementwondata",
    "awardachievement",
    "getfriendlist",
    "patch",
    "ping",
    "postactivity",
    "richpresencepatch",
    "startsession",
    "submitcodenote",
    "submitgametitle",
    "submitlbentry",
    "unlocks",
    "uploadachievement",
    "uploadleaderboard" => $validLogin && ($permissions >= Permissions::Registered),
    /*
     * Anything else is public. Includes login
     */
    default => true,
};

if (!$credentialsOK) {
    if (!$validLogin) {
        return DoRequestError("Invalid user/token combination.", 401, 'invalid_credentials');
    }

    if ($permissions < Permissions::Unregistered) { // Banned/Spam accounts
        return DoRequestError("Access denied.", 403, 'access_denied');
    }
    if ($permissions === Permissions::Unregistered) {
        return DoRequestError("Access denied. Please verify your email address.", 403, 'access_denied');
    }

    return DoRequestError("You do not have permission to do that.", 403, 'access_denied');
}

switch ($requestType) {
    /*
     * Login
     */
    case "login":
        $username = request()->input('u');
        $rawPass = request()->input('p');
        $response = authenticateForConnect($username, $rawPass, $token);

        // do not return $response['Status'] as an HTTP status code when using this
        // endpoint. legacy clients sometimes report the HTTP status code instead of
        // the $response['Error'] message.
        return response()->json($response);

    case "login2":
        $username = request()->input('u');
        $rawPass = request()->input('p');
        $response = authenticateForConnect($username, $rawPass, $token);
        break;

    /*
     * Global, no permissions required
     */
    case "allprogress":
        $consoleID = (int) request()->input('c');
        $response['Response'] = GetAllUserProgress($username, $consoleID);
        break;

    case "badgeiter":
        // Used by RALibretro achievement editor
        $response['FirstBadge'] = 80;
        $response['NextBadge'] = (int) FilenameIterator::getBadgeIterator();
        break;

    // TODO: Deprecate - not used anymore
    case "codenotes":
        if (!getCodeNotes($gameID, $codeNotesOut)) {
            return DoRequestError("FAILED!");
        }
        echo "OK:$gameID:";
        foreach ($codeNotesOut as $codeNote) {
            if (mb_strlen($codeNote['Note']) > 2) {
                $noteAdj = str_replace("\n", "\r\n", $codeNote['Note']);
                echo $codeNote['User'] . ':' . $codeNote['Address'] . ':' . $noteAdj . "#";
            }
        }
        break;

    case "codenotes2":
        $response['CodeNotes'] = getCodeNotesData($gameID);
        $response['GameID'] = $gameID;
        break;
    case "gameid":
        $md5 = request()->input('m') ?? '';
        $response['GameID'] = getGameIDFromMD5($md5);
        break;

    case "gameslist":
        $consoleID = (int) request()->input('c', 0);
        $response['Response'] = getGamesListDataNamesOnly($consoleID);
        break;

    case "officialgameslist":
        $consoleID = (int) request()->input('c', 0);
        $response['Response'] = getGamesListDataNamesOnly($consoleID, true);
        break;

    case "hashlibrary":
        $consoleID = (int) request()->input('c', 0);
        $response['MD5List'] = getMD5List($consoleID);
        break;

    case "latestclient":
        $emulatorId = (int) request()->input('e');
        $consoleId = (int) request()->input('c');

        if (empty($emulatorId) && !empty($consoleId)) {
            return DoRequestError("Lookup by Console ID has been deprecated");
        }

        $emulator = getEmulatorReleaseByIntegrationId($emulatorId);

        if ($emulator === null) {
            return DoRequestError("Unknown client");
        }
        $baseDownloadUrl = str_replace('https', 'http', config('app.url')) . '/';
        $response['MinimumVersion'] = $emulator['minimum_version'] ?? null;
        $response['LatestVersion'] = $emulator['latest_version'] ?? null;
        $response['LatestVersionUrl'] = null;
        if ($emulator['latest_version_url'] ?? null) {
            $response['LatestVersionUrl'] = $baseDownloadUrl . $emulator['latest_version_url'];
        }
        $response['LatestVersionUrlX64'] = ($emulator['latest_version_url_x64'] ?? null) ? $baseDownloadUrl . $emulator['latest_version_url_x64'] : null;
        break;

    case "latestintegration":
        $integration = getIntegrationRelease();
        if (!$integration) {
            return DoRequestError("Unknown client");
        }
        $baseDownloadUrl = str_replace('https', 'http', config('app.url')) . '/';
        $response['MinimumVersion'] = $integration['minimum_version'] ?? null;
        $response['LatestVersion'] = $integration['latest_version'] ?? null;
        $response['LatestVersionUrl'] = ($integration['latest_version_url'] ?? null)
            ? $baseDownloadUrl . $integration['latest_version_url']
            : 'http://retroachievements.org/bin/RA_Integration.dll';
        $response['LatestVersionUrlX64'] = ($integration['latest_version_url_x64'] ?? null)
            ? $baseDownloadUrl . $integration['latest_version_url_x64']
            : 'http://retroachievements.org/bin/RA_Integration-x64.dll';
        break;

    /*
     * User-based (require credentials)
     */

    case "ping":
        $game = Game::find($gameID);
        if ($user === null || $game === null) {
            $response['Success'] = false;
        } else {
            $activityMessage = request()->post('m');
            if ($activityMessage) {
                $activityMessage = utf8_sanitize($activityMessage);
            }

            PlayerSessionHeartbeat::dispatch($user, $game, $activityMessage);

            $response['Success'] = true;
        }
        break;

    case "achievementwondata":
        $friendsOnly = (bool) request()->input('f', 0);
        $response['Offset'] = $offset;
        $response['Count'] = $count;
        $response['FriendsOnly'] = $friendsOnly;
        $response['AchievementID'] = $achievementID;
        $response['Response'] = getRecentUnlocksPlayersData($achievementID, $offset, $count, $username, $friendsOnly);
        break;

    case "awardachievement":
        $achIDToAward = (int) request()->input('a', 0);
        $hardcore = (bool) request()->input('h', 0);

        /**
         * Prefer later values, i.e. allow AddEarnedAchievementJSON to overwrite the 'success' key
         * TODO refactor to optimistic update without unlock in place. what are the returned values used for?
         */
        $response = array_merge($response, unlockAchievement($user, $achIDToAward, $hardcore));

        if (Achievement::where('ID', $achIDToAward)->exists()) {
            dispatch(new UnlockPlayerAchievementJob($user->id, $achIDToAward, $hardcore))
                ->onQueue('player-achievements');
        }

        if (empty($response['Score'])) {
            $response['Score'] = $user->RAPoints;
            $response['SoftcoreScore'] = $user->RASoftcorePoints;
        }

        $response['AchievementID'] = $achIDToAward;
        break;

    case "getfriendlist":
        $response['Friends'] = GetFriendList($username);
        break;

    case "lbinfo":
        $lbID = (int) request()->input('i', 0);
        // Note: Nearby entry behavior has no effect if $username is null
        // TBD: friendsOnly
        $response['LeaderboardData'] = GetLeaderboardData($lbID, $username, $count, $offset, nearby: true);
        break;

    case "patch":
        $flag = (int) request()->input('f', 0);
        $response = GetPatchData($gameID, $user, $flag);
        break;

    case "postactivity":
        $activityType = (int) request()->input('a');
        if ($activityType != ActivityType::StartedPlaying) {
            return DoRequestError("You do not have permission to do that.", 403, 'access_denied');
        }

        $gameID = (int) request()->input('m');
        $game = Game::find($gameID);
        if (!$game) {
            return DoRequestError("Unknown game");
        }

        PlayerSessionHeartbeat::dispatch($user, $game);
        $response['Success'] = true;
        break;

    case "richpresencepatch":
        $response['Success'] = getRichPresencePatch($gameID, $richPresenceData);
        $response['RichPresencePatch'] = $richPresenceData;
        break;

    case "startsession":
        $game = Game::find($gameID);
        if (!$game) {
            return DoRequestError("Unknown game");
        }

        PlayerSessionHeartbeat::dispatch($user, $game);

        $response['Success'] = true;
        $userUnlocks = getUserAchievementUnlocksForGame($username, $gameID);
        foreach ($userUnlocks as $achId => $unlock) {
            if (array_key_exists('DateEarnedHardcore', $unlock)) {
                $response['HardcoreUnlocks'][] = [
                    'ID' => $achId,
                    'When' => strtotime($unlock['DateEarnedHardcore']),
                ];
            } else {
                $response['Unlocks'][] = [
                    'ID' => $achId,
                    'When' => strtotime($unlock['DateEarned']),
                ];
            }
        }
        $response['ServerNow'] = Carbon::now()->timestamp;
        break;

    case "submitcodenote":
        $note = request()->input('n') ?? '';
        $address = (int) request()->input('m', 0);
        $response['Success'] = submitCodeNote2($username, $gameID, $address, $note);
        $response['GameID'] = $gameID;     // Repeat this back to the caller?
        $response['Address'] = $address;    // Repeat this back to the caller?
        $response['Note'] = $note;      // Repeat this back to the caller?
        break;

    case "submitgametitle":
        $md5 = request()->input('m');
        $gameID = request()->input('g');
        $gameTitle = request()->input('i');
        $description = request()->input('d');
        $consoleID = request()->input('c');
        $response['Response'] = submitNewGameTitleJSON($username, $md5, $gameID, $gameTitle, $consoleID, $description);
        $response['Success'] = $response['Response']['Success']; // Passthru
        if (isset($response['Response']['Error'])) {
            $response['Error'] = $response['Response']['Error'];
        }
        break;

    case "submitlbentry":
        $lbID = (int) request()->input('i', 0);
        $score = (int) request()->input('s', 0);
        $validation = request()->input('v'); // Ignore for now?

        // TODO dispatch job or event/listener using an action

        $response['Response'] = SubmitLeaderboardEntry($username, $lbID, $score, $validation);
        $response['Success'] = $response['Response']['Success']; // Passthru
        if (!$response['Success']) {
            $response['Error'] = $response['Response']['Error'];
        }
        break;

    case "submitticket":
        $idCSV = request()->input('i');
        $problemType = request()->input('p');
        $comment = request()->input('n');
        $md5 = request()->input('m');
        $response['Response'] = submitNewTicketsJSON($username, $idCSV, $problemType, $comment, $md5);
        $response['Success'] = $response['Response']['Success']; // Passthru
        if (isset($response['Response']['Error'])) {
            $response['Error'] = $response['Response']['Error'];
        }
        break;

    case "unlocks":
        $hardcoreMode = (int) request()->input('h', 0) === UnlockMode::Hardcore;
        $userUnlocks = getUserAchievementUnlocksForGame($username, $gameID);
        if ($hardcoreMode) {
            $response['UserUnlocks'] = collect($userUnlocks)
                ->filter(fn ($value, $key) => array_key_exists('DateEarnedHardcore', $value))
                ->keys();
        } else {
            $response['UserUnlocks'] = array_keys($userUnlocks);
        }
        $response['GameID'] = $gameID;     // Repeat this back to the caller?
        $response['HardcoreMode'] = $hardcoreMode;
        break;

    case "uploadachievement":
        $errorOut = "";
        $response['Success'] = UploadNewAchievement(
            author: $username,
            gameID: $gameID,
            title: request()->input('n'),
            desc: request()->input('d'),
            points: (int) request()->input('z', 0),
            type: request()->input('x', 'not-given'), // `null` is a valid achievement type value, so we use a different fallback value.
            mem: request()->input('m'),
            flag: (int) request()->input('f', AchievementFlag::Unofficial),
            idInOut: $achievementID,
            badge: request()->input('b'),
            errorOut: $errorOut
        );
        $response['AchievementID'] = $achievementID;
        $response['Error'] = $errorOut;
        break;

    case "uploadleaderboard":
        $leaderboardID = (int) request()->input('i', 0);
        $newTitle = request()->input('n');
        $newDesc = request()->input('d') ?? '';
        $newStartMemString = request()->input('s');
        $newSubmitMemString = request()->input('b');
        $newCancelMemString = request()->input('c');
        $newValueMemString = request()->input('l');
        $newLowerIsBetter = (bool) request()->input('w', 0);
        $newFormat = request()->input('f');
        $newMemString = "STA:$newStartMemString::CAN:$newCancelMemString::SUB:$newSubmitMemString::VAL:$newValueMemString";

        $errorOut = "";
        $response['Success'] = UploadNewLeaderboard($username, $gameID, $newTitle, $newDesc, $newFormat, $newLowerIsBetter, $newMemString, $leaderboardID, $errorOut);
        $response['LeaderboardID'] = $leaderboardID;
        $response['Error'] = $errorOut;
        break;

    default:
        return DoRequestError("Unknown Request: '" . $requestType . "'");
}

$response['Success'] = (bool) $response['Success'];

if (array_key_exists('Status', $response)) {
    $status = $response['Status'];
    if ($status === 401) {
        return response()->json($response, $status)->header('WWW-Authenticate', 'Bearer');
    }

    return response()->json($response, $status);
}

return response()->json($response);
