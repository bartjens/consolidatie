<?php

class ConsolidatieEngine {

    private $templateIds=array();


    private $arrayData;
    private $patientData;
    private $dossierData;
    private $actueleData = array();
    private $toekomstData = array();
    private $historischeData = array();
    private $annuleerLijst = array();
    private $gestopteLijst = array();
    private $gestaakteMedicatie = array();
    private $checkList = array();
    private $parsedData = array();
    private $useAfspraakDatumTijdToSort = true;

    private $bouwsteenCodes = array();

    private $checkDatum = '';
    private $log = 0;

    public function __construct() {

    }

    public function process($inputData, $checkDatum) {
        $this->arrayData = $inputData;
        $linesMAPerMBH = array();
        $linesTAPerMBH = array();
        $linesMGBZPerMBH = array();
        $linesMGBPPerMBH = array();
        foreach ($this->arrayData as $id=>$line) {
            if (!$line['beschikbaar']) {
                continue;
            }
            if ($line['typeBouwsteen']=='MA')
                $linesMAPerMBH[$line['mbhID']][]=$line;

            if ($line['typeBouwsteen']=='TA')
                $linesTAPerMBH[$line['mbhID']][]=$line;

            if ($line['typeBouwsteen']=='MGBP')
                $linesMGBPPerMBH[$line['mbhID']][]=$line;
            if ($line['typeBouwsteen']=='MGBZ')
                $linesMGBZPerMBH[$line['mbhID']][]=$line;
        }

        $this->checkDatum = $checkDatum;
        // uasort($this->arrayData, array($this,'sorteerAntiChronologisch'));
        // echo '<pre>';
        $this->checkMedicatieAfspraken($linesMAPerMBH);
        // $this->checkToedieningsAfspraken($linesTAPerMBH);
        $this->checkMedicatieGebruik($linesMGBZPerMBH);
        $this->checkMedicatieGebruik($linesMGBPPerMBH);
        // echo '<pre>';
        foreach ($this->checkList as $mbhID => $mbhData) {
            foreach ($mbhData as $typeBS) {
                foreach ($typeBS as $line) {
                    // echo "\n" . $line['typeBouwsteen'] . "\t" . $line['id'] . "\t" . ($line['status']??'');

                    switch ($line['status']??'') {
                        case 'huidig' :
                            $this->actueleData[$mbhID][$line['id']] = $line;
                            break;
                        case 'gestopt' :
                            $this->historischeData[$mbhID][$line['id']] = $line;
                            break;

                        case 'toekomst' :
                            $this->toekomstData[$mbhID][$line['id']] = $line;
                            break;

                    }
                }
            }
        }

        // print_r($this->checkList);

        return array(
            'Actuele Medicatie'=>$this->actueleData,
            'Toekomstige Medicatie'=>$this->toekomstData,
            'Historische Medicatie'=>$this->historischeData,
            'traceInfo' => array(
                'annuleerLijst'=>$this->annuleerLijst,
                'gestaakteMedicatie'=>$this->gestaakteMedicatie
            )
            );


    }

    /*
    ****************************************************************
    ***** consolidatie Medicatie afspraken *************************
    ****************************************************************
    */

    private function checkMedicatieAfspraken($linesMAPerMBH) {
        foreach ($linesMAPerMBH as $mbhID => $lines) {
            uasort($lines, array($this,'sorteerAntiChronologisch'));

            $checkAfspraakDatum = $this->getEersteAfspraakDatumToekomstigeMas($lines);

            // echo "\ncheckAfspraakDatum $checkAfspraakDatum";

            // echo '<pre>';
            // print_r($lines);
            // echo '</pre>';
            foreach ($lines as $line) {

                $this->log( "\n<br/>checking line <strong>" . $line['simpelID'] . '</strong>');
                if ($result = $this->checkM1($line)=='Y') {
                    //heeft de MA een Annuleer vlag
                    $this->log( 'positive M1 ' . $result);
                    continue;
                }

                if ($result = $this->checkM2($line)=='Y') {
                    // Staat MA als referentie op geannuleerde  checklist
                    $this->log( 'positive M2 ' . $result);
                    continue;
                }

                if ($result = $this->checkM3($line)=='Y') {
                     //Heeft stop-datum in verleden
                    //We kunnen oudere MA's in deze MBH-ID negeren
                    $this->log('positive M3 ' . $result);
                    if ($checkAfspraakDatum!='') {
                        if ($line['afspraakDatumTijd'] < $checkAfspraakDatum)
                            break;
                    } else {
                        break;
                    }

                    continue;
                }

                if ($result = $this->checkM4($line)=="Y") {
                    //StartDatum/tijd In toekomst
                    //We kunnen oudere MA's in deze MBH-ID negeren (Want geen paralelle)

                    $this->log('positive M4 ' . $result);
                    if ($checkAfspraakDatum!='') {
                        if ($line['afspraakDatumTijd'] < $checkAfspraakDatum)
                            break;
                    } else {
                        break;
                    }
                }

            }


        }
    }






    private function CheckM1($line) {
        //heeft de MA een Annuleer vlag
        // $this->log( "Check M1 " . $line['simpelID']);
        $result = 'N';

        if ($line['stopCode']=='G') {
            $result = 'Y';
            $geannuleerdeID = $line['relaties'][0]['ID']??'XX';
            if ($geannuleerdeID)
                $this->annuleerLijst[$line['mbhID']][$geannuleerdeID]=$line['id'];
        }
        // //$this->log($result);
        return $result;

    }

    private function CheckM2($line) {

        // Staat MA als referentie op geannuleerde  checklist
        // $this->log( "Check M2 " . $line['simpelID']);
        $result = 'N';

        if (isset($this->annuleerLijst[$line['mbhID']][$line['id']])) {
            // Negeer MA (dus alleen op JA zetten en doorgaan met de volgende regel)
            $result = "Y";
        }


        // $this->log(" $result");
        return $result;
    }

    private function CheckM3($line) {
        //Heeft stop-datum in verleden
        // $this->log("Check M3 " . $line['simpelID']);
        $result = 'N';

        if ($line['stop'] < $this->checkDatum) {
            // Zet MA op Checklist. Oudere MA's kunnen genegeerd worden.
            $result = 'Y';
        }



        if ($line['stopCode']!='') {

            $referentie = $line['relaties'][0]['ID']??'';
            $referentieType = $line['relaties'][0]['soortRelatieMemoCode']??'';

            if ($referentieType=='MA') {
                $this->gestaakteMedicatie[$line['mbhID']][$referentie][] = $line['id'];
                // $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
            }

            if ($line['stopCode']=='O') {
                //het is een onderbroken medicatie.
                // check of er een nieuwere actuele is, of een nieuwere gestopte, zoniet, dan op actueel lijst
                if (!isset($this->checkList[$line['mbhID']])) {
                    $line['status'] = 'huidig';
                    $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
                }

                // if (!isset($this->historischeData[$line['mbhID']]) && !isset($this->actueleData[$line['mbhID']])) {
                //     $line['status'] = 'actueel';
                //     $this->checkList[$line['mbhID']][$line['id']] = $line;
                //     $this->actueleData[$line['mbhID']][$line['id']] = $line;
                // }
            }

        }

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            // De regelstaat in de gestaakte medicatielijst (dus is gestaakt door een nieuwere MA)
            // Dat betekent: niet actueel, maar ook niet historisch, dus uit de functie zonder verdere actie.
            $result = "Y";
            return $result;
        }

        if ($result == "Y") {
            if (!isset($this->checkList[$line['mbhID']])) {
                $line['status'] = 'gestopt';
                $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
            }

        }

        // //$this->log($result);
        return $result;
    }

    private function CheckM4($line) {
        //StartDatum/tijd In toekomst
        // $this->log( "Check M4 " . $line['simpelID']);
        // echo "\n" . $line['start'];
        // echo "\n" . $this->checkDatum;
        $result = 'N';

        if ($line['start'] > $this->checkDatum) {
            // MA is toekomst
            $line['status'] = 'toekomst';
            $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
            $result = 'Y';

        } else {
            //MA is huidig
            $checkarr = $line;
            $line['status'] = 'huidig';
            $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;


            //is het een stop?
            $referentie = $line['relaties'][0]['ID']??'';
            $referentieType = $line['relaties'][0]['soortRelatieMemoCode']??'';
            if ($line['stopCode']!='') {
                if ($referentieType=='MA') {
                    $this->gestaakteMedicatie[$line['mbhID']][$referentie][] = $line['id'];
                }

            }
        }
        // $this->log( " $result");
        return $result;
    }

    /*
    ****************************************************************
    ***** consolidatie toediening afspraken ************************
    ****************************************************************
    */

    private function checkToedieningsAfspraken($linesTAPerMBH) {
        foreach ($linesTAPerMBH as $mbhID => $lines) {
            uasort($lines, array($this,'sorteerAntiChronologisch'));
            foreach ($lines as $line) {

                if ($result = $this->checkT0($line)=='Y') {
                    //(T0) Staat de referentie MA op de checklist met status geannuleerd
                    $this->log( 'positive T0 ' . $result);
                    continue;
                }

                if ($result = $this->checkT1($line)=='Y') {
                    //(T0) is de TA geannuleerd
                    $this->log( 'positive T1 ' . $result);
                    continue;
                }

                if ($result = $this->checkT2($line)=='Y') {
                    //(T0) Staat de referentie TGA op de checklist met status geannuleerd
                    $this->log( 'positive T2 ' . $result);
                    continue;
                }

                if ($result = $this->checkT3($line)=='Y') {
                    //(T0) Staat de referentie TGA op de checklist met status geannuleerd
                    $this->log( 'positive T3 ' . $result);
                    if ($result = $this->checkT4($line)=='Y') {

                        if ($result = $this->checkT6($line)=='Y') {
                            // Is de gerefereerde MA een stop MA of is gestopt door een stop-ma, met een stopdatum in het verleden
                            // TA is niet actueel
                            continue;
                        }

                    } else {
                        if ($result = $this->checkT5($line)=='Y') {
                            // Is de TA afspraak datum eerder dan de afspraak datum van de oudste MA in de checklist
                            // TA is niet actueel
                            continue;
                        }
                    }

                    if ($result = $this->checkT7($line)=='Y') {
                        // (T7) is de TA een STOP-TA
                        if ($result = $this->checkT8($line)=='Y') {
                            // (T8) is stopdatumtijd in het verleden
                            continue;

                        } else {
                            if ($result = $this->checkT9($line)=='Y') {
                                // (T9) is startdatumtijd in de toekomst
                            } else {
                                // TA is actueel Zet TA op checklist stop-ta-actueel
                            }
                        }

                        continue;

                    }
                    if ($result = $this->checkT10($line)=='Y') {
                        // (T10) Staat de gerefereerde TA op de checklist en is gestopt
                        continue;

                    }
                    if ($result = $this->checkT11($line)=='Y') {
                        // (T11) Is de gerefereerde MA een gestopte MA met stopdatum in de toekomst
                        if ($result = $this->checkT15($line)=='Y') {
                            // (T15) Is startdatum TA in de toekomst
                            continue;
                        } else {

                        }
                        continue;

                    }
                    if ($result = $this->checkT12($line)=='Y') {
                        // (T12) is de startdatumtijd in de toekomst

                        if ($result = $this->checkT13($line)=='Y') {
                            // (T13) Staat de TA op de checklist STOP-TA-TOEKOMST
                            continue;

                        }

                        continue;

                    }

                    if ($result = $this->checkT14($line)=='Y') {
                        // (T14) Is stopdatumtijd TA in het verleden
                        continue;

                    }

                    // (T15) Is startdatum TA in de toekomst
                }
                $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
            }


        }
    }

    private function checkT0($line) {

         //heeft de MA een Annuleer vlag
         //(T0) Staat de referentie MA op de checklist met status geannuleerd
        //  $this->log( "Check T0 " . $line['simpelID']);
         $result = 'N';
         foreach ($line['relaties'] as $relatie) {
            if ($relatie['soortRelatieMemoCode']=='MA') {
                $refMA = $relatie['ID'];
                if (isset($this->annuleerLijst[$line['mbhID']][$refMA])) {
                    $this->annuleerLijst[$line['mbhID']][$line['id']]=$line['id'];
                }
            }
         }

         //$this->log($result);
         return $result;

    }

    private function checkT1($line) {

        //heeft de TA een Annuleer vlag
        //(T1) Heeft de TA zelf een annuleer vlag
        // $this->log( "Check T1 " . $line['simpelID']);
        $result = 'N';



        if ($line['stopCode']=='G') {
            $result = 'Y';
            $geannuleerdeID = $line['relaties'][0]['ID']??'';
            if ($geannuleerdeID)
                $this->annuleerLijst[$line['mbhID']][$geannuleerdeID]=$line['id'];
        }
        //$this->log($result);
        return $result;

   }

    private function checkT2($line) {

        //heeft de MA een Annuleer vlag
        //(T0) Staat de referentie MA op de checklist met status geannuleerd
        // $this->log( "Check T2 " . $line['simpelID']);
        $result = 'N';
        foreach ($line['relaties'] as $relatie) {
        if ($relatie['soortRelatieMemoCode']=='TA') {
            $refTA = $relatie['ID'];
            if (isset($this->annuleerLijst[$line['mbhID']][$refTA])) {
                $this->annuleerLijst[$line['mbhID']][$line['id']]=$line['id'];
            }
        }
        }

        //$this->log($result);
        return $result;

    }

    private function checkT3($line) {

        // (T3) Staat de MBH-ID op de checklist van MA’s
        // $this->log( "Check T3 " . $line['simpelID']);
        $result = 'N';

        if (isset($this->checkList[$line['mbhID']]['MA'])) {
            //Er is geen MA geregistreed met deze mbhID
            $result = 'Y';
        }

        //$this->log($result);
        return $result;

    }

    private function checkT4($line) {
        //(T4) Staat de gerefereerde MA op de checklist.
        $result = 'N';

        // $this->log( "Check T4 " . $line['simpelID']);
        foreach ($line['relaties'] as $relatie) {
            // print_r($relatie);
            if ($relatie['soortRelatieMemoCode']=='MA') {
                $refMA = $relatie['ID'];
                if (isset($this->checkList[$line['mbhID']]['MA'][$refMA])) {
                    $result = 'Y';
                }
            }
         }


        $this->log($result);
        return $result;
    }

    private function checkT5($line) {
        //Situatie: Vanuit T4: Er is geen referentie naar een MA die op de checklist staat, maar wel dezelfde MBH-ID

        //(T5) Is de afspraak datum eerder dan de afspraak datum van de laatste MA in de checklist
        $result = 'N';
        // echo '<pre>';
        // echo "\nCheck T5 " . $line['simpelID'] . "\n";

        // print_r($this->checkList);
        $maEersteAfspraakDatum = '';
        // print_r($this->checkList);
        foreach ($this->checkList[$line['mbhID']]['MA'] as $ma) {
            // echo "\n ma" . $ma['id'];
            // if ($maEersteAfspraakDatum=='') {
            //     $maEersteAfspraakDatum = $ma['afspraakDatumTijd'];
            //     continue;
            // }
            if ($maEersteAfspraakDatum < $ma['afspraakDatumTijd']) {
                $maEersteAfspraakDatum = $ma['afspraakDatumTijd'];
            }
        }
        if ($line['afspraakDatumTijd'] < $maEersteAfspraakDatum  ) {
            $result = 'Y';
        }

        //wat is de laatste MA in de checklist?
        //Zie ook dossier met tussentijdse aanpassing
        //$this->log($result);
        return $result;
    }
    private function checkT6($line) {
        // (T6) Is de gerefereerde MA een (STOP-MA of is gestopt door een stop-ma) en de stop-datum van de STOP-MA in het verleden

        $this->log( "Check T6 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT7($line) {
        // (T7) is de TA een STOP-TA
        // $this->log( "Check T7 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT8($line) {
        // (T8) is stopdatumtijd in het verleden
        // $this->log( "Check T8 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT9($line) {
        // (T9) is startdatumtijd in de toekomst
        // $this->log( "Check T9 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT10($line) {
        // (T10) Staat de gerefereerde TA op de checklist en is gestopt
        // $this->log( "Check T10 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT11($line) {
        // (T11) Is de gerefereerde MA een gestopte MA met stopdatum in de toekomst
        // $this->log( "Check T11 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT12($line) {
        // (T12) is de startdatumtijd in de toekomst
        // $this->log( "Check T12 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT13($line) {
        // (T13) Staat de TA op de checklist STOP-TA-TOEKOMST
        // $this->log( "Check T13 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkT14($line) {
        // (T14) Is stopdatumtijd TA in het verleden
        // $this->log( "Check T14 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }


    private function checkMedicatieGebruik($linesMGBPerMBH) {
        foreach ($linesMGBPerMBH as $mbhID => $lines) {
            uasort($lines, array($this,'sorteerAntiChronologisch'));
            foreach ($lines as $line) {
                if ($result = $this->checkMG1($line)=='Y') {
                    //(T0) Staat de referentie MA op de checklist met status geannuleerd
                    // $this->log( 'positive MG1 ' . $result);

                    if ($result = $this->checkMG2($line)=='Y') {
                        //(T0) Staat de referentie MA op de checklist met status geannuleerd
                        // $this->log( 'positive MG1 ' . $result);

                        continue;
                    } else {
                        if ($result = $this->checkMG6($line)=='Y') {
                            //(T0) Staat de referentie MA op de checklist met status geannuleerd
                            // $this->log( 'positive MG1 ' . $result);
                            continue;
                        } else {
                            //niet compleet
                            continue;
                        }
                    }
                    continue;
                }
                if ($result = $this->checkMG3($line)=='Y') {
                    // Is er een nieuwere MA/TA of MGB vanhetzelfde type
                    // $this->log( 'positive MG1 ' . $result);

                    continue;
                }
                if ($result = $this->checkMG4($line)=='Y') {
                    //Heeft stop-datum in verleden
                    // $this->log( 'positive MG1 ' . $result);

                    continue;
                }
                if ($result = $this->checkMG5($line)=='Y') {
                    //(T0) Staat de referentie MA op de checklist met status geannuleerd
                    // $this->log( 'positive MG1 ' . $result);

                    continue;
                }
            }
        }
    }

    private function checkMG1($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // GEEN IDEE WAAROM!!
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkMG2($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }

    private function checkMG3($line) {
        // Is er een nieuwere MA/TA of MGB vanhetzelfde type
        // $this->log( "Check MG1 " . $line['simpelID']);
        $bouwsteenType = $line['typeBouwsteen'];
        $result = 'N';
        if (isset($this->checkList[$line['mbhID']])) {
            foreach ($this->checkList[$line['mbhID']] as $type=>$data) {
                foreach ($data as $regel) {
                    if ($type=='MA' || $type=='TA' || $type==$bouwsteenType) {
                        if ($regel['afspraakDatumTijd']>$line['afspraakDatumTijd']) {
                            // echo "\n OUDERE MGB" . $line['typeBouwsteen'] . ':' . $line['simpelID'] . ' ' . $regel['typeBouwsteen'] . ':' . $regel['simpelID'];
                            $line['status'] = 'gestaakt';
                            return 'Y';
                        }
                    }
                }
            }
        }

        $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;

        //$this->log($result);
        return $result;
    }

    private function checkMG4($line) {
        //Heeft stop-datum in verleden
        // $this->log( "Check MG1 " . $line['simpelID']);
        $bouwsteenType = $line['typeBouwsteen'];
        $result = 'N';
        if ($line['stop'] < $this->checkDatum) {
            $result = 'Y';
            $line['status'] = 'gestopt';
        }

        $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;

        //$this->log($result);
        return $result;
    }

    private function checkMG5($line) {
        // StartDatum/tijd In toekomst
        // $this->log( "Check MG1 " . $line['simpelID']);
        $bouwsteenType = $line['typeBouwsteen'];
        $result = 'N';
        if ($line['start'] > $this->checkDatum) {
            // MGB is toekomst
            $line['status'] = 'toekomst';
            $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;
            $result = 'Y';

        } else {
            $line['status'] = 'huidig';
            $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;
        }

        //$this->log($result);
        return $result;
    }

    private function checkMG6($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';
        //$this->log($result);
        return $result;
    }




    private function getEersteAfspraakDatumToekomstigeMas($lines) {

        $afspraakDatumTijd = '';
        // echo '<pre>';
        foreach ($lines as $line) {
            // echo "\n" . $line['simpelID'];
            if ($afspraakDatumTijd > $line['start']) {
                continue;
            }
            if ($afspraakDatumTijd=='') {
                $afspraakDatumTijd = $line['afspraakDatumTijd'];
                continue;
            }

            if ($line['afspraakDatumTijd'] < $afspraakDatumTijd ) {
                $afspraakDatumTijd = $line['afspraakDatumTijd'];
            }
            // echo " $afspraakDatumTijd";
        }

        return $afspraakDatumTijd;

    }



    // public function showData() {
    //     echo '<h3> Actuele Lijst : </h3>';
    //     print_r($this->actueleData);

    //     echo '<h3> Toekomst Lijst : </h3>';
    //     print_r($this->toekomstData);

    //     echo '<h3> Annuleer Lijst : </h3>';
    //     print_r($this->annuleerLijst);
    // }


    // private function sorteerAntiChronologischStartDatum($a,$b) {
    //     if (($a['start']??'') < ($b['start']??''))
    //         return -1;

    //     return 1;

    // }

    // private function sorteerChronologisch($a,$b) {

    //     if ($a['mbhID'] < $b['mbhID'])
    //         return -1;
    //     if ($a['mbhID'] > $b['mbhID'])
    //         return 1;

    //     if ($a['afspraakDatumTijd'] < $b['afspraakDatumTijd'])
    //         return -1;

    //     if ($a['afspraakDatumTijd'] > $b['afspraakDatumTijd'])
    //         return 1;

    //     if (($a['start']??'') < ($b['start']??''))
    //         return -1;
    //     if (($a['start']??'') > ($b['start']??''))
    //         return 1;

    //     if (($a['stop']??'') < ($b['stop']??''))
    //         return -1;
    //     if (($a['stop']??'') > ($b['stop']??''))
    //         return 1;

    // }

    private function sorteerAntiChronologisch($a,$b) {
        //Dit is een wijziging t.o.v. de specs!
        //Sorteer eerst op de startdatum, dan op afspraakdatum

        if ($a['mbhID'] < $b['mbhID'])
            return -1;
        if ($a['mbhID'] > $b['mbhID'])
            return 1;

        // if ($a['afspraakDatumTijd'] > $b['afspraakDatumTijd'])
        //     return -1;

        // if ($a['afspraakDatumTijd'] < $b['afspraakDatumTijd'])
        //     return 1;


        if (($a['start']??'') > ($b['start']??''))
            return -1;
        if (($a['start']??'') < ($b['start']??''))
            return 1;

        if ($a['afspraakDatumTijd'] > $b['afspraakDatumTijd'])
            return -1;

        if ($a['afspraakDatumTijd'] < $b['afspraakDatumTijd'])
            return 1;

        if (($a['stop']??'') > ($b['stop']??''))
            return 1;
        if (($a['stop']??'') < ($b['stop']??''))
            return -1;





        return 1;
    }

    private function log($line) {
        if ($this->log) {
            echo '<pre>' . $line . '</pre>';
        }
    }

}