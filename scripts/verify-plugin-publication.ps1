[CmdletBinding()]
param(
    [string] $Origin = 'https://syndicatum.wizaya.com',
    [switch] $AllowInsecureLocal
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http

$originUri = [Uri]$Origin
$isLoopback = $originUri.IsLoopback
if ($originUri.AbsolutePath -ne '/' -or $originUri.Query -or $originUri.Fragment -or $originUri.UserInfo) {
    throw 'Origin must not include a path, query, fragment, or credentials.'
}
if ($originUri.Scheme -ne 'https' -and -not ($AllowInsecureLocal -and $isLoopback -and $originUri.Scheme -eq 'http')) {
    throw 'Publication verification requires HTTPS. Use -AllowInsecureLocal only for a loopback development server.'
}
$Origin = $Origin.TrimEnd('/')

$client = [System.Net.Http.HttpClient]::new()
$client.Timeout = [TimeSpan]::FromSeconds(20)

function Invoke-CheckRequest {
    param([string] $Method, [string] $Path, [object] $JsonBody = $null)
    $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::new($Method), "$Origin$Path")
    if ($null -ne $JsonBody) {
        $json = $JsonBody | ConvertTo-Json -Depth 12 -Compress
        $request.Content = [System.Net.Http.StringContent]::new($json, [Text.Encoding]::UTF8, 'application/json')
    }
    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $headers = @{}
        foreach ($header in $response.Headers) { $headers[$header.Key.ToLowerInvariant()] = @($header.Value) -join ', ' }
        foreach ($header in $response.Content.Headers) { $headers[$header.Key.ToLowerInvariant()] = @($header.Value) -join ', ' }
        [pscustomobject]@{
            Status = [int]$response.StatusCode
            Headers = $headers
            Body = $body
            Json = if ($body.TrimStart().StartsWith('{')) { $body | ConvertFrom-Json } else { $null }
        }
    } finally {
        $request.Dispose()
    }
}

function Assert-Check {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

$results = [System.Collections.Generic.List[object]]::new()
function Add-Pass { param([string] $Name, [string] $Detail) $results.Add([pscustomobject]@{ Check = $Name; Status = 'pass'; Detail = $Detail }) }

try {
    $health = Invoke-CheckRequest GET '/api/v1/health.php'
    Assert-Check ($health.Status -eq 200) "Health endpoint returned HTTP $($health.Status)."
    Assert-Check ($health.Json.data.service.id -eq 'syndicatum') 'Health endpoint did not identify Syndicatum.'
    Assert-Check ([bool]$health.Json.data.service.capabilities.mcp) 'Health endpoint did not advertise MCP.'
    Add-Pass 'health' 'Syndicatum service and MCP capability confirmed.'

    foreach ($page in @('support', 'privacy', 'terms')) {
        $response = Invoke-CheckRequest GET "/$page"
        Assert-Check ($response.Status -eq 200) "/$page returned HTTP $($response.Status)."
        Assert-Check ($response.Body -match 'Syndicatum') "/$page did not contain expected product content."
        Add-Pass $page 'Public page returned expected content.'
    }

    $authorizationMetadata = Invoke-CheckRequest GET '/.well-known/oauth-authorization-server'
    Assert-Check ($authorizationMetadata.Status -eq 200) 'OAuth authorization-server metadata is unavailable.'
    Assert-Check ($authorizationMetadata.Json.scopes_supported -contains 'offline_access') 'OAuth metadata does not advertise offline_access.'
    Assert-Check ($authorizationMetadata.Json.code_challenge_methods_supported -contains 'S256') 'OAuth metadata does not require PKCE S256.'
    Add-Pass 'oauth_metadata' 'Authorization server, refresh access, and PKCE metadata confirmed.'

    $protectedMetadata = Invoke-CheckRequest GET '/.well-known/oauth-protected-resource'
    Assert-Check ($protectedMetadata.Status -eq 200) 'OAuth protected-resource metadata is unavailable.'
    Assert-Check ($protectedMetadata.Json.resource -eq "$Origin/mcp") 'Protected resource audience does not match the canonical MCP URL.'
    Add-Pass 'resource_metadata' 'Canonical MCP resource audience confirmed.'

    $initialize = Invoke-CheckRequest POST '/mcp' @{ jsonrpc = '2.0'; id = 1; method = 'initialize'; params = @{} }
    Assert-Check ($initialize.Status -eq 200) "MCP initialize returned HTTP $($initialize.Status)."
    Assert-Check ($initialize.Json.result.serverInfo.name -eq 'chatgpt@syndicatum') 'MCP initialize returned an unexpected server identity.'
    Add-Pass 'mcp_initialize' "Protocol $($initialize.Json.result.protocolVersion) negotiated."

    $toolList = Invoke-CheckRequest POST '/mcp' @{ jsonrpc = '2.0'; id = 2; method = 'tools/list'; params = @{} }
    Assert-Check ($toolList.Status -eq 200) "MCP tools/list returned HTTP $($toolList.Status)."
    $interactive = @($toolList.Json.result.tools | Where-Object name -eq 'prepare_interactive_context')
    Assert-Check ($interactive.Count -eq 1) 'Reviewer-friendly prepare_interactive_context tool is missing.'
    Assert-Check ($interactive[0].annotations.destructiveHint -eq $false) 'Interactive context tool has an incorrect destructive annotation.'
    Add-Pass 'mcp_tools' "$(@($toolList.Json.result.tools).Count) tools discovered with interactive context support."

    $challenge = Invoke-CheckRequest POST '/mcp' @{ jsonrpc = '2.0'; id = 3; method = 'tools/call'; params = @{ name = 'list_projects'; arguments = @{} } }
    Assert-Check ($challenge.Status -eq 401) "Unauthenticated MCP call returned HTTP $($challenge.Status), expected 401."
    Assert-Check ($challenge.Headers['www-authenticate'] -match '^Bearer ') 'Unauthenticated MCP response omitted the Bearer challenge.'
    Assert-Check (@($challenge.Json.result._meta.'mcp/www_authenticate').Count -gt 0) 'Unauthenticated MCP response omitted MCP challenge metadata.'
    Add-Pass 'mcp_authentication' 'HTTP and MCP Bearer challenges confirmed.'

    if (-not $AllowInsecureLocal) {
        $security = Invoke-CheckRequest GET '/support'
        foreach ($header in @('strict-transport-security', 'x-content-type-options', 'referrer-policy', 'content-security-policy', 'permissions-policy')) {
            Assert-Check ($security.Headers.ContainsKey($header)) "Production response omitted $header."
        }
        Add-Pass 'security_headers' 'Required production response headers are present.'
    }

    $results | Format-Table -AutoSize
    Write-Host "Publication preflight passed for $Origin"
} finally {
    $client.Dispose()
}
