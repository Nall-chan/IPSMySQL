<?php

require_once(__DIR__ . "/../libs/MySQLArchiv.php");

/**
 * ArchiveControlMySQL Klasse für die das loggen von Variablen in einer MySQL Datenbank.
 * Erweitert ipsmodule.
 *
 * @package       MySQLArchiv
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0
 * @example <b>Ohne</b>
 *
 * @property array $Vars
 * @property mysqli $DB
 */
class ArchiveControlMySQL extends ipsmodule
{
    use BufferHelper,
        Database,
        DebugHelper,
        VariableWatch;

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Database', 'IPS');
        $this->RegisterPropertyString('Variables', json_encode(array()));

        $this->Vars = array();
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case VM_UPDATE:
                $this->LogValue($SenderID, $Data[0], $Data[1], $Data[3]);
                break;
            case VM_DELETE:
                $this->UnregisterVariableWatch($SenderID);
                $Vars = $this->Vars;
                unset($Vars[$SenderID]);
                $this->Vars = $Vars;
                break;
        }
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
        $Vars = $this->Vars;
        foreach (array_keys($Vars) as $VarId) {
            $this->UnregisterVariableWatch($VarId);
        }
        $this->Vars = array();
        $Vars = array();

        foreach ($ConfigVars as $Item) {
            $VarId = $Item['VariableId'];
            if ($VarId <= 0) {
                continue;
            }
            if (!IPS_VariableExists($VarId)) {
                continue;
            }
            if (array_key_exists($VarId, $Vars)) {
                continue;
            }
            $this->RegisterVariableWatch($VarId);
            $Vars[$VarId] = IPS_GetVariable($VarId)['VariableType'];
        }
        $this->Vars = $Vars;

        if ($this->ReadPropertyString('Host') == '') {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (!$this->Login()) {
            echo $this->Translate("Cannot connect to database.");
            $this->SetStatus(IS_EBASE + 2);
            return;
        }
        if (!$this->SelectDB()) {
            if (!$this->CreateDB()) {
                echo $this->Translate("Create database failed.");
                $this->SetStatus(IS_EBASE + 2);
                $this->Logout();
                return;
            }
        }
        $Result = true;
        foreach ($Vars as $VarId => $VarTyp) {
            if (!$this->TableExists($VarId)) {
                $Result = $Result && $this->CreateTable($VarId, $VarTyp);
            }
        }
        if (!$Result) {
            echo $this->Translate("Error on create tables.");
            $this->SetStatus(IS_EBASE + 3);
            $this->Logout();
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->Logout();
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
        $this->Login();
        $Database = $this->SelectDB();
        $Found = array();
        $TableVarIDs = $this->GetVariableTables();
        for ($Index = 0; $Index < count($ConfigVars); $Index++) {
            $Item = &$ConfigVars[$Index];
            $VarId = $Item['VariableId'];
            if ($Item['VariableId'] == 0) {
                $Item['Variable'] = sprintf($this->Translate("Object #%d not exists"), 0);
                $Item['rowColor'] = "#ff0000";
                continue;
            }

            if (!IPS_ObjectExists($VarId)) {
                $Item['Variable'] = sprintf($this->Translate("Object #%d not exists"), $VarId);
                $Item['rowColor'] = "#ff0000";
            } else {
                if (!IPS_VariableExists($VarId)) {
                    $Item['Variable'] = sprintf($this->Translate("Object #%d is no variable"), $VarId);
                    $Item['rowColor'] = "#ff0000";
                } else {
                    $Item['Variable'] = IPS_GetLocation($VarId);
                }
            }
            if ($Database) {
                $Result = $this->GetSummary($VarId);
                if (!$Result) {
                    $Item['Count'] = $this->Translate('unknown');
                    $Item['FirstTimestamp'] = $this->Translate('unknown');
                    $Item['LastTimestamp'] = $this->Translate('unknown');
                    $Item['Size'] = $this->Translate('unknown');
                } else {
                    $Item['Count'] = $Result['Count'];
                    $Item['FirstTimestamp'] = strftime('%c', $Result['FirstTimestamp']);
                    $Item['LastTimestamp'] = strftime('%c', $Result['LastTimestamp']);
                    $Item['Size'] = sprintf('%.2f MB', ((int) $Result['Size'] / 1024 / 1024), 2);
                    $Key = array_search(array('VariableID' => $VarId), $TableVarIDs);
                    if ($Key !== false) {
                        unset($TableVarIDs[$Key]);
                    }
                }
            } else {
                $Item['Count'] = $this->Translate('unknown');
                $Item['FirstTimestamp'] = $this->Translate('unknown');
                $Item['LastTimestamp'] = $this->Translate('unknown');
                $Item['Size'] = $this->Translate('unknown');
            }
            if (in_array($VarId, $Found)) {
                $Item['rowColor'] = "#ffff00";
                continue;
            }
            $Found[] = $VarId;
        }
        unset($Item);
        // Hier fehlen nicht mehr geloggte Variablen von denen aber noch Tabellen vorhanden sind
        //$ConfigVars = array_values($ConfigVars);
//        foreach ($TableVarIDs as $Var)
//        {
//            $Item = array('VariableId' => -1, 'Variable' => '');
//            if (IPS_VariableExists($Var['VariableID']))
//                $Item['Variable'] = IPS_GetLocation($Var['VariableID']);
//
//            $Result = $this->GetSummary($Var['VariableID']);
//            if (!$Result)
//            {
//                $Item['Count'] = 'Unbekannt';
//                $Item['FirstTimestamp'] = 'Unbekannt';
//                $Item['LastTimestamp'] = 'Unbekannt';
//                $Item['Size'] = 'Unbekannt';
//            }
//            else
//            {
//                $Item['Count'] = $Result['Count'];
//                $Item['FirstTimestamp'] = strftime('%c', $Result['FirstTimestamp']);
//                $Item['LastTimestamp'] = strftime('%c', $Result['LastTimestamp']);
//                $Item['Size'] = sprintf('%.2f MB', ((int) $Result['Size'] / 1024 / 1024), 2);
//                $Item['rowColor'] = "#ff0000";
//            }
//            $ConfigVars[] = $Item;
//        }
        //$this->SendDebug('FORM', $ConfigVars, 0);
        $form['elements'][4]['values'] = $ConfigVars;
        $this->Logout();
        return json_encode($form);
    }

    ################## PRIVATE

    /**
     * Werte loggen
     *
     * @access private
     * @param int $Variable VariablenID
     * @param mixed $NewValue Neuer Wert der Variable
     * @param bool $HasChanged true wenn neuer Wert vom alten abweicht
     * @param int $Timestamp Zeitstempel des neuen Wert
     */
    private function LogValue($Variable, $NewValue, $HasChanged, $Timestamp)
    {
        $Vars = $this->Vars;
        if (!array_key_exists($Variable, $Vars)) {
            return false;
        }
        if (!$this->Login()) {
            if ($this->DB) {
                echo $this->DB->connect_error;
            }
            return false;
        }
        if (!$this->SelectDB()) {
            echo $this->DB->error;
            return false;
        }
        switch ($Vars[$Variable]) {
            case vtBoolean:
                $result = $this->WriteValue($Variable, (int) $NewValue, $HasChanged, $Timestamp);
                break;
            case vtInteger:
                $result = $this->WriteValue($Variable, $NewValue, $HasChanged, $Timestamp);
                break;
            case vtFloat:
                $result = $this->WriteValue($Variable, sprintf('%F', $NewValue), $HasChanged, $Timestamp);
                break;
            case vtString:
                $result = $this->WriteValue($Variable, "'" . $this->DB->real_escape_string($NewValue) . "'", $HasChanged, $Timestamp);
                break;
        }
        if (!$result) {
            $this->SendDebug('Error on write', $Variable, 0);
        }
        return $this->Logout();
    }

    /**
     * Anmelden am MySQL-Server uns auswählen der Datenbank.
     * Für alle public Methoden, welche Fehler ausgeben sollen.
     *
     * @access private
     * @return bool True bei Erfolg, sonst false.
     */
    private function LoginAndSelectDB()
    {
        if (!$this->Login()) {
            if ($this->DB) {
                trigger_error($this->DB->connect_error, E_USER_NOTICE);
            } else {
                trigger_error($this->Translate('No host for database'), E_USER_NOTICE);
            }
            return false;
        }
        if (!$this->SelectDB()) {
            trigger_error($this->DB->error, E_USER_NOTICE);
            return false;
        }
        return true;
    }

    ################## PUBLIC

    /**
     * IPS-Instant-Funktion ACMYSQL_ChangeVariableID
     * Zum überführen von geloggten Daten auf eine neue Variable.
     *
     * @access public
     * @param int $OldVariableID Alte VariablenID
     * @param int $NewVariableID Neue VariablenID
     * @return bool True bei Erfolg, sonst false.
     */
    public function ChangeVariableID(int $OldVariableID, int $NewVariableID)
    {
        if (!IPS_VariableExists($NewVariableID)) {
            trigger_error($this->Translate('NewVariableID is no variable.'), E_USER_NOTICE);
            return false;
        }

        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        $Vars = $this->Vars;

        if (array_key_exists($NewVariableID, $Vars)) {
            trigger_error($this->Translate('NewVariableID is allready logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        if (!$this->TableExists($OldVariableID)) {
            trigger_error($this->Translate('OldVariableID was not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        if (IPS_GetVariable($NewVariableID)['VariableType'] != $this->GetLoggedDataTyp($OldVariableID)) {
            trigger_error($this->Translate('Old and new Datatyp not match.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }


        if (!$this->RenameTable($OldVariableID, $NewVariableID)) {
            trigger_error($this->Translate('Error on rename table.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
        foreach ($ConfigVars as &$Item) {
            if ($Item['VariableId'] == $OldVariableID) {
                $Item['VariableId'] = $NewVariableID;
            }
        }
        $Variables = json_encode($ConfigVars);
        IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_DeleteVariableData
     * Zum löschen einer Zeitspanne von Werten.
     *
     * @access public
     * @param int $VariableID VariablenID der zu löschenden Daten.
     * @param int $Startzeit Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit Endzeitpunkt als UnixTimestamp
     * @return bool True bei Erfolg, sonst false.
     */
    public function DeleteVariableData(int $VariableID, int $Startzeit, int $Endzeit)
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('No data or VariableID found.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $Result = $this->DeleteData($VariableID, $Startzeit, $Endzeit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on delete data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetLoggedValues
     * Liefert geloggte Daten einer Variable
     *
     * @access public
     * @param int $VariableID VariablenID der zu liefernden Daten.
     * @param int $Startzeit Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit Endzeitpunkt als UnixTimestamp
     * @param int $Limit Anzahl der max. Datensätze. Bei 0 wird das HardLimit genutzt.
     * @return array Datensätze
     */
    public function GetLoggedValues(int $VariableID, int $Startzeit, int $Endzeit, int $Limit)
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) or ($Limit == 0)) {
            $Limit = IPS_GetOption('ArchiveRecordLimit');
        }

        if ($Endzeit == 0) {
            $Endzeit = time();
        }

        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID was not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $Result = $this->GetLoggedData($VariableID, $Startzeit, $Endzeit, $Limit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on fetch data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        switch ($this->GetLoggedDataTyp($VariableID)) {
            case vtBoolean:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (bool) $Item['Value'];
                }
                break;
            case vtInteger:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (int) $Item['Value'];
                }

                break;
            case vtFloat:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (float) $Item['Value'];
                }
                break;
            case vtString:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }

        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetLoggingStatus
     * Liefert ob eine Variable aktuell geloggt wird.
     *
     * @access public
     * @param int $VariableID Die zu prüfende VariablenID
     * @return bool True wenn logging aktiv ist.
     */
    public function GetLoggingStatus(int $VariableID)
    {
        $Vars = $this->Vars;
        return array_key_exists($VariableID, $Vars);
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_SetLoggingStatus
     * De-/Aktiviert das logging einer Variable.
     * Wird erst nach IPS_Applychanges($MySQLArchivID) aktiv.
     *
     * @access public
     * @param int $VariableID Die zu loggende VariablenID
     * @param bool $Aktiv True zum logging aktivieren, false zum deaktivieren.
     * @return bool True bei Erfolg, sonst false.
     */
    public function SetLoggingStatus(int $VariableID, bool $Aktiv)
    {
        $Vars = $this->Vars;
        if ($Aktiv) { //aktivieren
            if (array_key_exists($VariableID, $Vars)) {
                trigger_error($this->Translate('VariableID is allready logged.'), E_USER_NOTICE);
                return false;
            }
            if (!IPS_VariableExists($VariableID)) {
                trigger_error($this->Translate('VariableID is no Variable.'), E_USER_NOTICE);
                return false;
            }
            $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
            $ConfigVars[] = array('VariableId' => $VariableID);
            $Variables = json_encode($ConfigVars);
            IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
            return true;
        } else { //deaktivieren
            if (!array_key_exists($VariableID, $Vars)) {
                trigger_error($this->Translate('VariableID was not logged.'), E_USER_NOTICE);
                return false;
            }
            $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
            foreach ($ConfigVars as $Index => &$Item) {
                if ($Item['VariableId'] == $VariableID) {
                    array_splice($ConfigVars, $Index, 1);
                    $ConfigVars = array_values($ConfigVars);
                    $Variables = json_encode($ConfigVars);
                    IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetAggregationType
     * Liefert immer 0, da Typ Zähler nicht unterstützt wird.
     *
     * @access public
     * @param int $VariableID VariablenID der zu liefernden Daten.
     * @return int 0
     */
    public function GetAggregationType(int $VariableID)
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return 0; //Standard, Zähler wird nicht unterstützt
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetGraphStatus
     * Liefert immer true, da diese Funktion nicht unterstützt wird.
     *
     * @access public
     * @param int $VariableID
     * @return bool immer True, außer VariableID wird nicht geloggt.
     */
    public function GetGraphStatus(int $VariableID)
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_SetGraphStatus
     * Liefert immer true, da diese Funktion nicht unterstützt wird.
     *
     * @access public
     * @param int $VariableID VariablenID
     * @param bool $Aktiv ohne Funktion
     * @return bool immer True, außer VariableID wird nicht geloggt.
     */
    public function SetGraphStatus(int $VariableID, bool $Aktiv)
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetAggregatedValues
     * Liefert aggregierte Daten einer geloggte Variable
     *
     * @access public
     * @param int $VariableID VariablenID der zu liefernden Daten.
     * @param int $Aggregationsstufe
     * @param int $Startzeit Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit Endzeitpunkt als UnixTimestamp
     * @param int $Limit Anzahl der max. Datensätze. Bei 0 wird das HardLimit genutzt.
     * @return array Datensätze
     */
    public function GetAggregatedValues(int $VariableID, int $Aggregationsstufe, int $Startzeit, int $Endzeit, int $Limit)
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) or ($Limit == 0)) {
            $Limit = IPS_GetOption('ArchiveRecordLimit');
        }

        if ($Endzeit == 0) {
            $Endzeit = time();
        }

        if (($Aggregationsstufe < 0) or ($Aggregationsstufe > 6)) {
            trigger_error($this->Translate('Invalid Aggregationsstage'), E_USER_NOTICE);
            return false;
        }

        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $Result = $this->GetAggregatedData($VariableID, $Aggregationsstufe, $Startzeit, $Endzeit, $Limit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on fetch data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        switch ($this->GetLoggedDataTyp($VariableID)) {
            case vtBoolean:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (bool) $Item['Min'];
                    $Item['Avg'] = (bool) $Item['Avg'];
                    $Item['Max'] = (bool) $Item['Max'];
                }
                break;
            case vtInteger:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (int) $Item['Min'];
                    $Item['Avg'] = (int) $Item['Avg'];
                    $Item['Max'] = (int) $Item['Max'];
                }

                break;
            case vtFloat:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (float) $Item['Min'];
                    $Item['Avg'] = (float) $Item['Avg'];
                    $Item['Max'] = (float) $Item['Max'];
                }
                break;
            case vtString:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }

        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instant-Funktion ACMYSQL_GetAggregationVariables
     * Liefert eine Übersicht über alle geloggte Daten.
     *
     * @access public
     * @param bool $DatenbankAbfrage ohne Funktion.
     * @return array Datensätze
     */
    public function GetAggregationVariables(bool $DatenbankAbfrage)
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        $Data = $this->GetVariableTables();
        $Vars = $this->Vars;
        foreach ($Data as &$Item) {
            $Result = $this->GetSummary($Item['VariableID']);
            $Item['RecordCount'] = (int) $Result['Count'];
            $Item['FirstTime'] = (int) $Result['FirstTimestamp'];
            $Item['LastTime'] = (int) $Result['LastTimestamp'];
            $Item['RecordSize'] = (int) $Result['Size'];
            $Item['AggregationType'] = 0;
            $Item['AggregationVisible'] = true;
            $Item['AggregationActive'] = array_key_exists($Item['VariableID'], $Vars);
        }
        return $Data;
        /*
         * FirstTime	integer	Datum/Zeit vom Beginn des Aggregationszeitraums als Unix Zeitstempel
          LastTime	integer	Datum/Zeit vom letzten Eintrag des Aggregationszeitraums als Unix Zeitstempel
          RecordCount	integer	Anzahl der Datensätze
          RecordSize	integer	Größe aller Datensätze in Bytes
          VariableID	integer	ID der Variable
          AggregationType	integer	Aggregationstyp als Integer. Siehe auch AC_GetAggregationType
          AggregationVisible	boolean	Gibt an ob die Variable in der Visualisierung angezeigt wird. Siehe auch AC_GetGraphStatus
          AggregationActive	boolean	Gibt an ob das Logging für diese Variable Aktiv ist. Siehe auch AC_GetLoggingStatus
         */
    }
}

/** @} */
