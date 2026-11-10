# Admin screens over HTTP against the wp-env site (http://localhost:8888), after admin-seed.php.
# Checks escaping of hostile content (S1), nonces (S2), capability checks (S3), release and delete.
# Usage (from plugin/): powershell -File tests\wp\admin-check.ps1 -Ids "1 2 3"   (pwsh on Linux in CI)
param([Parameter(Mandatory = $true)][string]$Ids)

$ErrorActionPreference = 'Stop'
# Windows PowerShell aliases "curl" to Invoke-WebRequest, so call the real binary by name there.
$curl = if ($PSVersionTable.PSEdition -eq 'Desktop' -or $IsWindows) { 'curl.exe' } else { 'curl' }
$base = 'http://localhost:8888'
$page = "$base/wp-admin/admin.php?page=laas-quarantine"
$evil, $real, $orphan = $Ids.Split(' ')
$tmp = Join-Path ([IO.Path]::GetTempPath()) "laas-admin-check"
New-Item -ItemType Directory -Force $tmp | Out-Null
$script:failed = $false

function Check([string]$what, [bool]$ok) {
    Write-Output ("{0} {1}" -f ($(if ($ok) { 'ok  ' } else { 'FAIL' }), $what))
    if (-not $ok) {
        $script:failed = $true
        if ($env:GITHUB_ACTIONS) { Write-Output "::error title=admin-check::$what" } # Shows as an annotation.
    }
}

function Login([string]$user, [string]$pass, [string]$jar) {
    Remove-Item $jar -ErrorAction SilentlyContinue
    & $curl -s -o (Join-Path $tmp 'login.html') -c $jar -b 'wordpress_test_cookie=WP%20Cookie%20check' `
        --data-urlencode "log=$user" --data-urlencode "pwd=$pass" -d 'wp-submit=Log+In&testcookie=1' "$base/wp-login.php"
}

# Returns @{ Code; Location; Body }.
function Request([string]$jar, [string]$url, [string[]]$form = @()) {
    $headers = Join-Path $tmp 'headers.txt'
    $bodyFile = Join-Path $tmp 'body.html'
    $curlArgs = @('-s', '-D', $headers, '-o', $bodyFile, '-b', $jar, '-c', $jar)
    foreach ($field in $form) { $curlArgs += @('--data-urlencode', $field) }
    $curlArgs += $url
    & $curl @curlArgs | Out-Null
    $head = Get-Content $headers -Raw
    $code = [int]([regex]::Match($head, 'HTTP/\S+ (\d{3})').Groups[1].Value)
    $location = [regex]::Match($head, '(?im)^Location:\s*(\S+)').Groups[1].Value
    @{ Code = $code; Location = $location; Body = (Get-Content $bodyFile -Raw -Encoding UTF8) }
}

function NoRawXss([string]$what, [string]$html) {
    $raw = @('<script>alert(1)', '<img src=x onerror', '<svg onload')
    $hit = $raw | Where-Object { $html.Contains($_) }
    Check "$what has no unescaped payload" (-not $hit)
}

$admin = Join-Path $tmp 'admin.jar'
Login 'admin' 'password' $admin

$list = Request $admin $page
Check "list loads for admin ($($list.Code))" ($list.Code -eq 200)
NoRawXss 'list' $list.Body
Check 'list shows the escaped payload' ($list.Body.Contains('&lt;script&gt;alert(1)'))
$sarah = $list.Body.IndexOf('Sarah Mitchell'); $xss = $list.Body.IndexOf('x@evil.example')
Check 'lowest score first (0.93 above 0.9999)' ($sarah -gt 0 -and $xss -gt $sarah)
Check 'score bar has a text alternative' ($list.Body.Contains('aria-label="Score 0.93 out of 1"'))

$detail = Request $admin "$page&item=$evil"
Check "detail loads ($($detail.Code))" ($detail.Code -eq 200)
NoRawXss 'detail' $detail.Body
Check 'detail links are not clickable' (-not $detail.Body.Contains('href="https://evil.example'))
Check 'detail explains the threshold' ($detail.Body.Contains('Messages scoring'))

$nonce = [regex]::Match($list.Body, 'name="_wpnonce" value="([0-9a-f]+)"').Groups[1].Value
Check 'list form carries a nonce' ($nonce -ne '')
if ($nonce -eq '' -and $env:GITHUB_ACTIONS) {
    # Show what the page actually contained (CI logs need a login; annotations don't).
    $start = [Math]::Max(0, $list.Body.IndexOf('id="wpbody-content"'))
    $snippet = ($list.Body.Substring($start, [Math]::Min(3000, $list.Body.Length - $start)) -replace '\s+', ' ')
    Write-Output "::error title=list page body::$snippet"
}

$noNonce = Request $admin $page @("laas_row_action=delete:$evil")
Check "POST without nonce refused ($($noNonce.Code))" ($noNonce.Code -eq 403)

$release = Request $admin $page @("_wpnonce=$nonce", "laas_row_action=release:$real")
Check "release redirects with success ($($release.Location))" ($release.Code -eq 302 -and $release.Location -match 'laas_done=release' -and $release.Location -match 'laas_ok=1')
$offer = Request $admin $release.Location
Check 'success notice offers to always allow the sender' ($offer.Body.Contains('Always allow sarah@example.co.uk?'))
# The "Always allow" form sits in the notice, above the list form, so its nonce comes first.
$rulesNonce = [regex]::Match($offer.Body, 'name="_wpnonce" value="([0-9a-f]+)"').Groups[1].Value
$allow = Request $admin "$base/wp-admin/admin.php?page=laas-rules" @("_wpnonce=$rulesNonce", 'laas_rule_op=add', 'kind=email', 'value=sarah@example.co.uk', 'rule_action=allow')
Check "always allow adds the rule ($($allow.Location))" ($allow.Location -match 'laas_rule=added')
$rulesPage = Request $admin "$base/wp-admin/admin.php?page=laas-rules"
Check 'rules screen lists the allowed sender' ($rulesPage.Code -eq 200 -and $rulesPage.Body.Contains('<code>sarah@example.co.uk</code>'))
$rNonce = [regex]::Match($rulesPage.Body, 'name="_wpnonce" value="([0-9a-f]+)"').Groups[1].Value
$bad = Request $admin "$base/wp-admin/admin.php?page=laas-rules" @("_wpnonce=$rNonce", 'laas_rule_op=add', 'kind=email', 'value=<script>x', 'rule_action=block')
Check "invalid rule refused ($($bad.Location))" ($bad.Location -match 'laas_rule=invalid')
$ruleId = [regex]::Match($rulesPage.Body, 'name="rule_id" value="(\d+)"').Groups[1].Value
$remove = Request $admin "$base/wp-admin/admin.php?page=laas-rules" @("_wpnonce=$rNonce", 'laas_rule_op=delete', "rule_id=$ruleId")
Check "rule removed ($($remove.Location))" ($remove.Location -match 'laas_rule=removed')

$fail = Request $admin $page @("_wpnonce=$nonce", "laas_row_action=release:$orphan")
Check "release without a form reports failure ($($fail.Location))" ($fail.Location -match 'laas_failed=1')
$after = Request $admin ($page + '&laas_done=release&laas_ok=0&laas_failed=1')
Check 'failure notice says the message is still held' ($after.Body.Contains("It's still in quarantine") -or $after.Body.Contains('It&#039;s still in quarantine'))
Check 'failed row still listed as held' ($after.Body.Contains('jo@example.org'))

$delete = Request $admin $page @("_wpnonce=$nonce", 'action=delete', "ids[]=$evil")
Check "bulk delete redirects with success ($($delete.Location))" ($delete.Location -match 'laas_done=delete' -and $delete.Location -match 'laas_ok=1')
$released = Request $admin ($page + '&status=released')
Check 'released view lists the released enquiry' ($released.Body.Contains('sarah@example.co.uk'))

$settings = Request $admin "$base/wp-admin/admin.php?page=laas-settings"
Check "settings load ($($settings.Code))" ($settings.Code -eq 200 -and $settings.Body.Contains('Sensitivity'))
$diag = Request $admin "$base/wp-admin/admin.php?page=laas-settings&tab=diagnostics"
$expected = ([regex]::Matches($diag.Body, 'as expected')).Count
Check "diagnostics self-test: 3 of 3 as expected (got $expected)" ($expected -eq 3)

$optNonce = [regex]::Match($settings.Body, 'name="_wpnonce" value="([0-9a-f]+)"').Groups[1].Value
$save = Request $admin "$base/wp-admin/options.php" @('option_page=laas_settings_group', 'action=update', "_wpnonce=$optNonce",
    'laas_settings[strength]=strict', 'laas_settings[retention_days]=999', 'laas_settings[subject_tag]=<b>[Maybe]</b>',
    'laas_settings[protect_known][]=cf7', 'laas_settings[protect_known][]=comment', 'laas_settings[protect][cf7]=1')
Check "settings save redirects ($($save.Code))" ($save.Code -eq 302)
$saved = (Request $admin "$base/wp-admin/admin.php?page=laas-settings").Body
Check 'strength saved as strict' ($saved -match 'value="strict"\s+checked')
Check 'retention capped at 365' ($saved -match 'retention_days\]" value="365"')
Check 'tag stripped of HTML' ($saved -match 'subject_tag\]" value="\[Maybe\]"')
Check 'comments switched off, CF7 on' (($saved -match 'protect\]\[cf7\]" value="1"\s+checked') -and -not ($saved -match 'protect\]\[comment\]" value="1"\s+checked'))
Request $admin "$base/wp-admin/options.php" @('option_page=laas_settings_group', 'action=update', "_wpnonce=$optNonce",
    'laas_settings[strength]=balanced', 'laas_settings[retention_days]=30', 'laas_settings[subject_tag]=[Possible spam]', 'laas_settings[store_ip]=1',
    'laas_settings[protect_known][]=cf7', 'laas_settings[protect_known][]=comment', 'laas_settings[protect][cf7]=1', 'laas_settings[protect][comment]=1') | Out-Null

$sub = Join-Path $tmp 'sub.jar'
Login 'laas_sub' 'laas-sub-pass' $sub
$subList = Request $sub $page
Check "subscriber cannot open the quarantine ($($subList.Code))" ($subList.Code -eq 403)
$subPost = Request $sub $page @("_wpnonce=$nonce", "laas_row_action=delete:$orphan")
Check "subscriber cannot post actions ($($subPost.Code))" ($subPost.Code -eq 403)
$subRules = Request $sub "$base/wp-admin/admin.php?page=laas-rules" @("_wpnonce=$rNonce", 'laas_rule_op=add', 'kind=domain', 'value=evil.example', 'rule_action=allow')
Check "subscriber cannot add rules ($($subRules.Code))" ($subRules.Code -eq 403)

$anon = Join-Path $tmp 'anon.jar'
Remove-Item $anon -ErrorAction SilentlyContinue
$anonList = Request $anon $page
Check "logged-out visitor is sent to login ($($anonList.Code))" ($anonList.Code -eq 302 -and $anonList.Location -match 'wp-login')

if ($script:failed) { Write-Output 'FAIL'; exit 1 } else { Write-Output 'OK'; exit 0 }
