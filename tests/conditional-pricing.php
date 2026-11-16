<?php
// Deterministic server calculation tests; no WordPress installation or lead submission.
define('ABSPATH', __DIR__);
define('HGM_PLUGIN_PATH', dirname(__DIR__) . '/');
function add_action(...$args) {}
function absint($v) { return abs((int)$v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($v)); }
function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
function get_post_meta($id, $key, $single) { return $GLOBALS['steps']; }
require HGM_PLUGIN_PATH . 'includes/handle-form.php';
function eq($actual, $expected, $message) { if ($actual !== $expected) { fwrite(STDERR, "$message: " . var_export($actual, true) . ' != ' . var_export($expected, true) . "\n"); exit(1); } }
$widths = ['5 inch', '6 inch'];
$base = [[[960,2400],[1920,2880],[2880,3840]], [[1200,3000],[2400,3600],[3600,4800]]];
$stories = [[0,0],[50,950],[100,1350]];
$GLOBALS['steps'] = [
 ['options'=>array_map(function($w){return ['label'=>$w,'low'=>0,'high'=>0];}, $widths)],
 ['options'=>array_map(function($i) use ($base, $widths) {return ['label'=>'size '.($i+1), 'low'=>0, 'high'=>0, 'conditional_prices'=>array_map(function($j) use($base,$widths,$i){return ['step'=>1,'label'=>$widths[$j],'low'=>$base[$j][$i][0],'high'=>$base[$j][$i][1]];},[0,1])];},[0,1,2])],
 ['options'=>array_map(function($i)use($stories){return ['label'=>'story '.($i+1),'low'=>$stories[$i][0],'high'=>$stories[$i][1]];},[0,1,2])],
];
foreach ($widths as $w=>$label) foreach ([0,1,2] as $s) foreach ([0,1,2] as $t) {
 $answers=['step_3'=>['label'=>'story '.($t+1),'min'=>999999], 'step_2'=>['label'=>'size '.($s+1)], 'step_1'=>['label'=>$label]];
 $r=hgm_calculate_server_side_estimate(3018,$answers);
 eq($r['low_total'], (float)($base[$w][$s][0]+$stories[$t][0]),"low $w/$s/$t");
 eq($r['high_total'], (float)($base[$w][$s][1]+$stories[$t][1]),"high $w/$s/$t");
}
$missing=hgm_calculate_server_side_estimate(3018,['step_2'=>['label'=>'size 1']]);
eq($missing['pricing_valid'],false,'missing condition must not silently quote');
$missing_priced=hgm_calculate_server_side_estimate(3018,['step_1'=>['label'=>'5 inch'], 'step_3'=>['label'=>'story 1']]);
eq($missing_priced['pricing_valid'],false,'missing priced question must not silently quote');
$forged=hgm_calculate_server_side_estimate(3018,['step_1'=>['label'=>'fake'],'step_2'=>['label'=>'size 1']]);
eq($forged['pricing_valid'],false,'unrecognized condition must not silently quote');
$GLOBALS['steps']=[['options'=>[['label'=>'old','low'=>100,'high'=>250]]],['options'=>[['label'=>'addition','low'=>50,'high'=>100]]]];
$r=hgm_calculate_server_side_estimate(3320,['step_1'=>['label'=>'old','min'=>9999], 'step_2'=>['label'=>'addition']]);
eq($r['low_total'],150.0,'legacy low'); eq($r['high_total'],350.0,'legacy high');
$roof_base=[[6000,12000],[7200,16200],[9000,24000],[12000,18000],[14400,24300],[18000,36000],[18000,24000],[21600,32400],[27000,48000]];
$roof_stories=[[0,0],[500,1500],[2000,3000]];
$GLOBALS['steps']=[['options'=>array_map(function($i)use($roof_base){return ['label'=>'roof '.($i+1),'low'=>$roof_base[$i][0],'high'=>$roof_base[$i][1]];},range(0,8))],['options'=>array_map(function($i)use($roof_stories){return ['label'=>'story '.($i+1),'low'=>$roof_stories[$i][0],'high'=>$roof_stories[$i][1]];},range(0,2))]];
foreach(range(0,8) as $i) foreach(range(0,2) as $j) {
 $r=hgm_calculate_server_side_estimate(3320,['step_1'=>['label'=>'roof '.($i+1)], 'step_2'=>['label'=>'story '.($j+1)]]);
 eq($r['low_total'],(float)($roof_base[$i][0]+$roof_stories[$j][0]),"roof low $i/$j");
 eq($r['high_total'],(float)($roof_base[$i][1]+$roof_stories[$j][1]),"roof high $i/$j");
}
echo "PASS: 18 gutter combinations; missing, forged, and reordered answers; 27 roofing combinations; untrusted prices\n";
