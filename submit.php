<?php
// submit.php
//
// GoDaddy replacement for netlify/functions/submission-created.js. Every form
// on the site posts here. It saves the submission, answers the visitor right
// away, then does the slower work (emails, Google Sheets, Claude) afterwards.
//
// Secrets live in config.php (copy config.sample.php) - see DEPLOY-GODADDY.md.

define('CR_APP', true);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

$FORMS = array('smart-contact', 'quiz-result', 'vault-signup', 'standards-generator');

// Spam trap: real visitors never see this field.
if (!empty($_POST['bot-field'])) {
    echo '{"ok":true}';
    exit;
}

$form = isset($_POST['form-name']) ? (string)$_POST['form-name'] : '';
if (!in_array($form, $FORMS, true)) {
    http_response_code(400);
    echo '{"ok":false,"error":"unknown form"}';
    exit;
}

$data = array();
foreach ($_POST as $key => $value) {
    if (is_string($value) && $key !== 'form-name' && $key !== 'bot-field') {
        $data[(string)$key] = mb_substr(trim($value), 0, 5000);
    }
}

if (isset($data['email']) && $data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo '{"ok":false,"error":"invalid email"}';
    exit;
}

// Basic abuse guard: the Standards Generator costs real money per request.
$limits = array('standards-generator' => 5, 'smart-contact' => 10, 'quiz-result' => 10, 'vault-signup' => 10);
if (cr_rate_limited($form, $limits[$form])) {
    http_response_code(429);
    echo '{"ok":false,"error":"too many requests"}';
    exit;
}

cr_save_submission($form, $data);

// Answer the visitor now; the rest runs after the connection closes.
ignore_user_abort(true);
@set_time_limit(180);
$body = '{"ok":true}';
header('Connection: close');
header('Content-Length: ' . strlen($body));
echo $body;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    switch ($form) {
        case 'smart-contact':
            $topic = isset($data['topic']) ? $data['topic'] : '';
            if ($topic === 'resource') {
                handle_free_resource($data);
            } elseif ($topic === 'standard') {
                handle_lesson_standard($data);
            } else {
                handle_general_contact($data);
            }
            break;
        case 'quiz-result':
            handle_quiz_result($data);
            break;
        case 'vault-signup':
            handle_vault_signup($data);
            break;
        case 'standards-generator':
            handle_standards_generator($data);
            break;
    }
} catch (Throwable $e) {
    cr_log('Handler error for ' . $form . ': ' . $e->getMessage());
}
exit;

// ---------------------------------------------------------------------------

function cr_rate_limited($form, $maxPerHour) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $file = cr_data_dir('ratelimit') . '/' . md5($form . '|' . $ip) . '.json';
    $now = time();
    $hits = array();
    if (is_file($file)) {
        $stored = json_decode((string)@file_get_contents($file), true);
        if (is_array($stored)) {
            foreach ($stored as $t) {
                if ($t > $now - 3600) {
                    $hits[] = $t;
                }
            }
        }
    }
    if (count($hits) >= $maxPerHour) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

function post_to_sheet($configKey, $payload) {
    $url = cr_cfg($configKey);
    if ($url === '') {
        return false;
    }
    list($status, $body) = cr_post_json($url, array(), $payload);
    if ($status < 200 || $status >= 400) {
        cr_log($configKey . ' webhook error ' . $status . ': ' . $body);
        return false;
    }
    return true;
}

function notify_angella($subject, $text, $replyTo = '') {
    return cr_resend(
        'Chalk & Roots Website <noreply@chalkandroots.com>',
        'chalkandroots@gmail.com',
        $subject,
        '',
        $text,
        $replyTo
    );
}

function handle_general_contact($data) {
    $toEmail = isset($data['email']) ? $data['email'] : '';
    if ($toEmail === '') {
        return;
    }
    $name = !empty($data['name']) ? $data['name'] : 'there';
    $topic = !empty($data['subtopic']) ? $data['subtopic'] : 'General inquiry';
    $message = isset($data['message']) ? $data['message'] : '';

    $text = "Hi $name,\n\n" .
        "Thanks so much for reaching out through Chalk & Roots! This is a quick automatic " .
        "note to let you know your message came through.\n\n" .
        "Angella reads every message personally and will get back to you soon.\n\n" .
        "In the meantime, feel free to explore free and premium resources on Ko-fi: " .
        "https://ko-fi.com/chalkandroots/shop\n\n" .
        "Warmly,\nChalk & Roots\nchalkandroots.com";

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#35302B;line-height:1.6;max-width:520px;margin:auto">' .
        '<h2 style="color:#7A9B7A;font-family:Georgia,serif">Chalk &amp; Roots</h2>' .
        '<p>Hi ' . cr_esc($name) . ',</p>' .
        '<p>Thanks so much for reaching out through Chalk &amp; Roots! This is a quick automatic note to let you know your message came through.</p>' .
        '<p>Angella reads every message personally and will get back to you soon.</p>' .
        '<p>In the meantime, feel free to explore free and premium resources on ' .
        '<a href="https://ko-fi.com/chalkandroots/shop" style="color:#C4714A">Ko-fi</a>.</p>' .
        '<p>Warmly,<br>Chalk &amp; Roots<br><a href="https://chalkandroots.com" style="color:#6B4C3B">chalkandroots.com</a></p></div>';

    cr_resend(
        'Chalk & Roots <noreply@chalkandroots.com>',
        $toEmail,
        'Thanks for reaching out to Chalk & Roots!',
        $html,
        $text,
        'chalkandroots@gmail.com'
    );

    notify_angella(
        'New contact form message: ' . $topic,
        "New message from your website contact form:\n\n" .
        "Name: $name\nEmail: $toEmail\nTopic: $topic\n\nMessage:\n$message",
        $toEmail
    );
}

function handle_free_resource($data) {
    $email = isset($data['email']) ? $data['email'] : '';
    if ($email === '') {
        return;
    }
    $gradeBand = !empty($data['grade-band']) ? $data['grade-band'] : '(not provided)';
    $resource = !empty($data['resource']) ? $data['resource'] : '(not provided)';
    $source = !empty($data['source']) ? $data['source'] : 'direct';

    $logged = post_to_sheet('SHEETS_WEBHOOK_LEADS', array(
        'form' => 'free-resource-gate',
        'email' => $email,
        'gradeBand' => $gradeBand,
        'resource' => $resource,
        'source' => $source,
        'timestamp' => gmdate('c'),
    ));
    if (!$logged) {
        // No sheet configured (or it failed): don't let the lead go unseen.
        notify_angella(
            'New free resource request: ' . $resource,
            "Email: $email\nGrade band: $gradeBand\nResource: $resource\nSource: $source",
            $email
        );
    }
}

function handle_quiz_result($data) {
    $email = isset($data['email']) ? $data['email'] : '';
    if ($email === '') {
        return;
    }
    $timeDrain = !empty($data['biggest-time-drain']) ? $data['biggest-time-drain'] : '(not provided)';
    $gradeBand = !empty($data['grade-band']) ? $data['grade-band'] : '(not provided)';
    $goal = !empty($data['goal']) ? $data['goal'] : '(not provided)';
    $source = !empty($data['source']) ? $data['source'] : 'direct';

    $logged = post_to_sheet('SHEETS_WEBHOOK_LEADS', array(
        'form' => 'quiz-result',
        'email' => $email,
        'timeDrain' => $timeDrain,
        'gradeBand' => $gradeBand,
        'goal' => $goal,
        'source' => $source,
        'timestamp' => gmdate('c'),
    ));
    if (!$logged) {
        notify_angella(
            'New teaching superpower quiz result',
            "Email: $email\nBiggest time drain: $timeDrain\nGrade band: $gradeBand\nGoal: $goal\nSource: $source",
            $email
        );
    }
}

// On Netlify these only landed in the Forms dashboard; with that gone,
// Angella gets an email so sign-ups aren't missed.
function handle_vault_signup($data) {
    $email = isset($data['email']) ? $data['email'] : '';
    if ($email === '') {
        return;
    }
    $source = !empty($data['source']) ? $data['source'] : 'direct';
    notify_angella(
        'New AI Prompt Vault sign-up',
        "Email: $email\nSource: $source",
        $email
    );
}

function handle_lesson_standard($data) {
    $name = !empty($data['name']) ? $data['name'] : 'there';
    $email = isset($data['email']) ? $data['email'] : '';
    $standard = isset($data['standard']) ? $data['standard'] : '';

    post_to_sheet('SHEETS_WEBHOOK_LESSON_STANDARD', array(
        'name' => $name,
        'email' => $email,
        'standard' => $standard,
    ));

    if ($email !== '') {
        notify_angella(
            'New standards request: ' . ($standard !== '' ? $standard : '(no standard given)'),
            "New \"Building a lesson from a language standard?\" request:\n\n" .
            "Name: $name\nEmail: $email\nStandard: $standard",
            $email
        );
    }
}

// Flow: teacher submits the form -> Claude drafts a plan -> the draft is saved
// and a signed approval link is emailed to Angella -> she opens the link and
// presses Send (approve.php) -> the plan is emailed to the teacher.
function handle_standards_generator($data) {
    $name = !empty($data['name']) ? $data['name'] : 'there';
    $email = isset($data['email']) ? $data['email'] : '';
    $standard = isset($data['standard']) ? trim($data['standard']) : '';
    $gradeBand = !empty($data['grade-band']) ? $data['grade-band'] : '(not provided)';
    $durationRaw = !empty($data['duration']) ? $data['duration'] : 'single-lesson';
    if ($durationRaw === 'full-unit') {
        $durationLabel = 'a full unit (2-4 weeks)';
    } elseif ($durationRaw === 'one-week') {
        $durationLabel = 'one week';
    } else {
        $durationLabel = 'a single lesson (1 day)';
    }

    if ($email === '' || $standard === '') {
        return;
    }
    $anthropicKey = cr_cfg('ANTHROPIC_API_KEY');
    if ($anthropicKey === '') {
        cr_log('ANTHROPIC_API_KEY is not set - cannot draft plan');
        return;
    }
    if (cr_cfg('APPROVAL_SECRET') === '') {
        cr_log('APPROVAL_SECRET is not set - cannot build a safe approval link');
        return;
    }
    $siteUrl = rtrim(cr_cfg('SITE_URL', 'https://chalkandroots.com'), '/');

    $systemPrompt =
        'You are an instructional designer writing a Common Core ELA/ELD lesson ' .
        'or unit plan for a K-12 teacher. Respond with ONLY valid JSON (no markdown ' .
        'fences, no commentary) matching this exact shape: ' .
        '{"title":"string","standardExplanation":"1-2 sentence plain-English ' .
        'explanation of what this standard requires","objectives":["string", ...],' .
        '"materials":["string", ...],"schedule":[{"label":"e.g. Day 1 or Week 1",' .
        '"focus":"short phrase","activities":["string", ...]}],' .
        '"instructionalRoutine":' .
        '["step-by-step teaching routine, one string per step", ...]}. ' .
        'Keep language warm, practical, and classroom-ready.';
    $userPrompt =
        "Standard code: $standard\nGrade band: $gradeBand\nDuration: $durationLabel\n\n" .
        'Build the full plan now, matching the JSON shape exactly.';

    list($status, $resp) = cr_post_json(
        'https://api.anthropic.com/v1/messages',
        array('x-api-key: ' . $anthropicKey, 'anthropic-version: 2023-06-01'),
        array(
            'model' => cr_cfg('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'max_tokens' => 8000,
            'system' => $systemPrompt,
            'messages' => array(array('role' => 'user', 'content' => $userPrompt)),
        ),
        150
    );
    if ($status !== 200) {
        cr_log("Anthropic API error $status: $resp");
        notify_angella(
            'Standards Generator failed: ' . $standard,
            "The plan for $name <$email> could not be drafted (Anthropic API status $status).\n" .
            "Standard: $standard ($gradeBand), $durationLabel\n\nCheck data/error.log on the server.",
            $email
        );
        return;
    }

    $ai = json_decode($resp, true);
    $raw = '';
    if (isset($ai['content']) && is_array($ai['content'])) {
        foreach ($ai['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') {
                $raw .= $block['text'];
            }
        }
    }
    $cleaned = trim(preg_replace('/^```(?:json)?\s*|```\s*$/', '', trim($raw)));
    $plan = json_decode($cleaned, true);
    if (!is_array($plan)) {
        cr_log('Could not parse plan JSON: ' . substr($raw, 0, 500));
        notify_angella(
            'Standards Generator failed: ' . $standard,
            "The drafted plan for $name <$email> could not be read.\n" .
            "Standard: $standard ($gradeBand), $durationLabel",
            $email
        );
        return;
    }

    // The draft is stored on the server and the link carries only a random id
    // plus its signature, so even a full-unit plan makes a short, safe link.
    $id = bin2hex(random_bytes(16));
    file_put_contents(
        cr_data_dir('pending') . '/' . $id . '.json',
        json_encode(array(
            'name' => $name,
            'email' => $email,
            'standard' => $standard,
            'gradeBand' => $gradeBand,
            'durationLabel' => $durationLabel,
            'plan' => $plan,
            'createdAt' => gmdate('c'),
            'sent' => false,
        ), JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    $approveUrl = $siteUrl . '/approve.php?id=' . $id . '&sig=' . cr_sign($id);

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#35302B;line-height:1.6;max-width:640px;margin:auto">' .
        '<h2 style="color:#7A9B7A;font-family:Georgia,serif">New Standards Generator request</h2>' .
        '<p><b>Teacher:</b> ' . cr_esc($name) . ' &lt;' . cr_esc($email) . '&gt;<br>' .
        '<b>Standard:</b> ' . cr_esc($standard) . ' (' . cr_esc($gradeBand) . ')<br>' .
        '<b>Duration:</b> ' . cr_esc($durationLabel) . '</p>' .
        '<hr style="border:none;border-top:1px solid #C8D9C8;margin:20px 0">' .
        cr_plan_html($plan, false) .
        '<div style="text-align:center;margin:28px 0"><a href="' . cr_esc($approveUrl) . '" ' .
        'style="display:inline-block;background:#C9A227;color:#4A3428;font-weight:900;padding:14px 26px;border-radius:999px;text-decoration:none">' .
        '🌟 Review &amp; Send to ' . cr_esc($name) . ' &#8594;</a></div>' .
        '<p style="font-size:.85rem;color:#6f6760">Nothing is sent to the teacher until you open that link and press Send. ' .
        'If this draft needs edits, just reply to this email with what to change instead.</p></div>';

    cr_resend(
        'Chalk & Roots Standards Generator <noreply@chalkandroots.com>',
        'chalkandroots@gmail.com',
        'Review: ' . $standard . ' plan for ' . $name,
        $html,
        '',
        $email
    );
}

// Plan body shared by the review email (here) and the teacher email (approve.php).
function cr_plan_html($plan, $emoji) {
    $li = function ($items) {
        $out = '';
        foreach ((array)$items as $item) {
            $out .= '<li>' . cr_esc(is_string($item) ? $item : json_encode($item)) . '</li>';
        }
        return $out;
    };
    $schedule = '';
    foreach ((array)(isset($plan['schedule']) ? $plan['schedule'] : array()) as $day) {
        $label = isset($day['label']) ? $day['label'] : '';
        $focus = isset($day['focus']) ? $day['focus'] : '';
        $schedule .= '<div style="margin:14px 0;padding:14px;background:#FBF5EA;border-radius:10px">' .
            '<p style="margin:0 0 6px;font-weight:800;color:#4A3428">' . cr_esc($label) . ' — ' . cr_esc($focus) . '</p>' .
            '<ul style="margin:0;padding-left:20px;color:#35302B">' .
            $li(isset($day['activities']) ? $day['activities'] : array()) . '</ul></div>';
    }
    $h = 'font-weight:800;color:#4A3428';
    return
        '<h3 style="font-family:Georgia,serif;color:#4A3428;border-bottom:2px solid #C9A227;padding-bottom:6px">' .
        cr_esc(isset($plan['title']) ? $plan['title'] : 'Your Plan') . '</h3>' .
        '<p style="color:#6f6760;font-style:italic">' . cr_esc(isset($plan['standardExplanation']) ? $plan['standardExplanation'] : '') . '</p>' .
        '<p style="' . $h . '">' . ($emoji ? '📌 ' : '') . 'Learning Objectives</p><ul>' . $li(isset($plan['objectives']) ? $plan['objectives'] : array()) . '</ul>' .
        '<p style="' . $h . '">' . ($emoji ? '🧰 ' : '') . 'Materials &amp; Resources</p><ul>' . $li(isset($plan['materials']) ? $plan['materials'] : array()) . '</ul>' .
        '<p style="' . $h . '">' . ($emoji ? '🗓️ ' : '') . 'Schedule</p>' . $schedule .
        '<p style="' . $h . '">' . ($emoji ? '👩‍🏫 ' : '') . 'Instructional Routine</p><ol>' . $li(isset($plan['instructionalRoutine']) ? $plan['instructionalRoutine'] : array()) . '</ol>';
}
