<?php
if (!defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION')) { define('VARIABLE_PRESENTATION_VALUE_PRESENTATION', '{3319437D-7CDE-699D-750A-3C6A3841FA75}'); }
// Nachgebildetes Symcon für den Prüfstand (Muster aus dem Automat-Prüfstand, auf das Nötige gekürzt).

$GLOBALS['OBJ'] = [];
$GLOBALS['NEXT'] = 60000;
$GLOBALS['MODULES'] = [];
$GLOBALS['CONF_PROP'] = [];
$GLOBALS['PROFILES'] = [
    '~Switch' => ['MaxValue' => 1, 'Suffix' => ''],
    '~Shutter' => ['MaxValue' => 100, 'Suffix' => ' %'],
    '~Shutter.Reversed' => ['MaxValue' => 100, 'Suffix' => ' %'],
    '~Intensity.100' => ['MaxValue' => 100, 'Suffix' => ' %'],
    'Wind.ms' => ['MaxValue' => 60, 'Suffix' => ' m/s'],
];

function mkinst(int $id, string $name, int $parent = 0, string $guid = ''): void
{
    $GLOBALS['OBJ'][$id] = ['kind' => 1, 'name' => $name, 'parent' => $parent, 'ident' => ''];
    if ($guid !== '') {
        $GLOBALS['MODULES'][$guid][] = $id;
    }
}
function mkvar(int $id, int $type, $value, string $name, int $parent, bool $action = false, string $profile = '', string $ident = ''): void
{
    $GLOBALS['OBJ'][$id] = ['kind' => 2, 'name' => $name, 'parent' => $parent, 'ident' => $ident, 'type' => $type, 'value' => $value, 'action' => $action, 'profile' => $profile];
}
function fire(IPSModule $m, int $vid, $v): void
{
    $old = $GLOBALS['OBJ'][$vid]['value'];
    $GLOBALS['OBJ'][$vid]['value'] = $v;
    $m->MessageSink($GLOBALS['CLOCK'], $vid, VM_UPDATE, [$v, $old !== $v, $old, $GLOBALS['CLOCK']]);
}

function IPS_ObjectExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]); }
function IPS_InstanceExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['kind'] === 1; }
function IPS_VariableExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['kind'] === 2; }
function IPS_GetVariable($id): array
{
    $o = $GLOBALS['OBJ'][(int)$id];
    return ['VariableType' => $o['type'], 'VariableProfile' => '', 'VariableCustomProfile' => $o['profile'], 'VariablePresentation' => isset($o['suffix']) ? ['SUFFIX' => $o['suffix']] : [], 'VariableCustomPresentation' => [], 'VariableUpdated' => $o['updated'] ?? 0];
}
function GetValueFormatted($id): string { if (isset($GLOBALS['GVF'][(int)$id])) { return $GLOBALS['GVF'][(int)$id]; } $v = $GLOBALS['OBJ'][(int)$id]['value']; return is_bool($v) ? ($v ? 'true' : 'false') : str_replace('.', ',', (string)$v); }
function IPS_GetVariableList(): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['kind'] === 2)); }
function GetValue($id) { return $GLOBALS['OBJ'][(int)$id]['value']; }
function SetValue($id, $v): bool { $GLOBALS['OBJ'][(int)$id]['value'] = $v; return true; }
function HasAction($id): bool { return (bool)($GLOBALS['OBJ'][(int)$id]['action'] ?? false); }
function RequestAction($id, $v): bool { if ((int)$id !== (int)($GLOBALS['STECKT'] ?? -1)) { $GLOBALS['OBJ'][(int)$id]['value'] = $v; } $GLOBALS['BEFEHLE'][] = [(int)$id, $v]; return true; }
function IPS_GetObject(int $id): array { $o = $GLOBALS['OBJ'][$id]; return ['ObjectIdent' => $o['ident'], 'ObjectName' => $o['name'], 'ParentID' => $o['parent'], 'ObjectInfo' => $o['info'] ?? '']; }
function IPS_SetInfo(int $id, string $info): bool { $GLOBALS['OBJ'][$id]['info'] = $info; return true; }
function IPS_GetLinkList(): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['kind'] === 6)); }
function IPS_GetLink(int $id): array { return ['TargetID' => $GLOBALS['OBJ'][$id]['target'] ?? 0]; }
function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    foreach ($GLOBALS['OBJ'] as $id => $o) {
        if ($o['ident'] === $ident && $o['parent'] === $parent) {
            return $id;
        }
    }
    trigger_error('Ident nicht gefunden', E_USER_WARNING);
    return false;
}
function IPS_GetChildrenIDs(int $p): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['parent'] === $p)); }
function IPS_GetName(int $id): string { return $GLOBALS['OBJ'][$id]['name'] ?? ''; }
function IPS_GetParent(int $id): int { return $GLOBALS['OBJ'][$id]['parent'] ?? 0; }
function IPS_GetVariablePresentation(int $id): array { return $GLOBALS['OBJ'][$id]['pres'] ?? []; }
function IPS_GetInstanceList(): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['kind'] === 1)); }
function IPS_GetInstance(int $id): array
{
    $guid = '';
    foreach ($GLOBALS['MODULES'] ?? [] as $g => $ids) {
        if (in_array($id, $ids, true)) {
            $guid = $g;
        }
    }
    return ['InstanceID' => $id, 'InstanceStatus' => 102, 'ModuleInfo' => ['ModuleID' => $guid, 'ModuleName' => '']];
}
function IPS_GetInstanceListByModuleID(string $guid): array { return $GLOBALS['MODULES'][$guid] ?? []; }
function IPS_GetModuleList(): array { return array_keys($GLOBALS['MODULINFO'] ?? []); }
function IPS_GetModule(string $guid): array { return $GLOBALS['MODULINFO'][$guid] ?? []; }
function IPS_GetProperty(int $id, string $n) { return $GLOBALS['CONF_PROP'][$id][$n] ?? ''; }
function IPS_VariableProfileExists(string $p): bool { return isset($GLOBALS['PROFILES'][$p]); }
function IPS_GetVariableProfile(string $p): array { return $GLOBALS['PROFILES'][$p]; }
// Bewusst kein IPS_LogMessage/IPS_SetProperty/IPS_ApplyChanges: ein Aufruf im Modul wäre ein Fatal Error.

class IPSModule
{
    public int $InstanceID;
    public array $props = [];
    public array $attrs = [];
    public int $status = 0;
    public array $timers = [];
    public array $messages = [];
    public array $debug = [];
    public array $fieldUpdates = [];
    public array $logged = [];

    public function __construct($InstanceID = 12345) { $this->InstanceID = (int)$InstanceID; }
    public function Translate(string $t): string { return $GLOBALS['DE'][$t] ?? $t; }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyInteger(string $n, int $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyFloat(string $n, float $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyBoolean(string $n, bool $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function ReadPropertyString(string $n): string { return (string)$this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return (int)$this->props[$n]; }
    public function ReadPropertyFloat(string $n): float { return (float)$this->props[$n]; }
    public function ReadPropertyBoolean(string $n): bool { return (bool)$this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeInteger(string $n, int $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeBoolean(string $n, bool $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function ReadAttributeString(string $n): string { return $this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return $this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return $this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $s): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $s, int $m): void { $this->messages[$s][$m] = true; }
    public function UnregisterMessage(int $s, int $m): void { unset($this->messages[$s][$m]); if (empty($this->messages[$s])) { unset($this->messages[$s]); } }
    public function GetMessageList(): array { $o = []; foreach ($this->messages as $s => $m) { $o[$s] = array_keys($m); } return $o; }
    public function SendDebug(string $a, string $b, int $c): void { $this->debug[] = "$a: $b"; }
    public function LogMessage(string $m, int $t): void { $this->logged[] = [$t, $m]; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    public array $kachel = [];
    public array $puffer = [];
    public function SetVisualizationType(int $t): void {}
    public function UpdateVisualizationValue($v): void { $this->kachel[] = $v; }
    public function SetBuffer(string $n, string $v): void { $this->puffer[$n] = $v; }
    public function GetBuffer(string $n): string { return $this->puffer[$n] ?? ''; }
    public function GetIDForIdent(string $ident) { return IPS_GetObjectIDByIdent($ident, $this->InstanceID); }
    public function MaintainVariable(string $ident, string $name, int $type, $profile, int $pos, bool $keep): void
    {
        $profile = is_array($profile) ? '' : $profile;
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($keep && $id === false) {
            mkvar($GLOBALS['NEXT']++, $type, [false, 0, 0.0, ''][$type], $name, $this->InstanceID, false, $profile, $ident);
        }
        if (!$keep && $id !== false) {
            unset($GLOBALS['OBJ'][$id]);
        }
    }
    public function EnableAction(string $ident): void { $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID); if ($id !== false) { $GLOBALS['OBJ'][$id]['action'] = true; } }
    public function SetValue(string $ident, $v): void { $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)]['value'] = $v; }
    public function GetValue(string $ident) { return $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)]['value']; }
}
