<?php
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) putenv(trim($parts[0]) . '=' . trim($parts[1]));
    }
}
loadEnv(__DIR__ . '/.env');

require 'AiProviderService.php';
$aiService = new AiProviderService('gemini-pro');

$systemPrompt = <<<PROMPT
You are a Senior UK Quantity Surveyor.
Analyze each BoQ Bill and map it to the SINGLE best Works Package.

TARGET WORKS PACKAGES:
- wd_0: Demolition
- wd_1: Groundworks

Evaluate both the Works Package match and the Subcontractor Trade independently.
For each bill, return:
1. "bill_number": integer
2. "target_wd_id": string (e.g. "wd_3" or "unmapped")
3. "package_confidence": integer (0 to 100) representing confidence in the works package selection
4. "trade": string (specific subcontractor trade, e.g. "Dryliner / Plasterer", "Groundworker", "Glazier")
5. "trade_confidence": integer (0 to 100) representing confidence in the subcontractor trade assignment
6. "rationale": concise 1-sentence commercial explanation citing specific items or materials

Return a valid JSON array of objects with NO markdown formatting:
[
  {
    "bill_number": 1,
    "target_wd_id": "wd_2",
    "package_confidence": 95,
    "trade": "Demolition Contractor",
    "trade_confidence": 98,
    "rationale": "Commercial justification..."
  }
]
PROMPT;

$userContent = "Analyze and map these BoQ Bills:\n\nBill 1: \"Demolition\"\nScope: Break out walls";

$response = $aiService->prompt($systemPrompt, $userContent);
echo "RAW RESPONSE:\n";
echo $response;
