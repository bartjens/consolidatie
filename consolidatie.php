<?php

class ConsolidatieEngine {

    private $templateIds=array();


    private $arrayData;
    private $actueleData = array();
    private $toekomstData = array();
    private $annuleerLijst = array();
    private $gestopteLijst = array();
    private $gestaakteMedicatie = array();
    private $skipList = array();
    // Gestaakte medicatie is een lijst van bouwstenen die zijn gestopt door een andere bouwsteen.
    // $gestaakteMedicatie[MBHID][GEESTOPTEBOUWSTEEN][STOPBOUWSTEEN] = TYPEBOUWSTEEN
    // Een MA kan alleen door een MA worden gestopt
    // De andere bouwstenen kunnen door meerdere bouwstenen worden gestopt (een TA door een stop-ta en een stop-ma, etc)

    private $checkList = array();


    private $checkAfspraakDatum;
    private $lowerCheckDatum;
    private $checkDatum = '';
    private $log = 0;

    public function __construct() {

    }

    public function process($inputData, $checkDatum,$lowerCheckMargin) {
        echo '<pre>';
        $this->arrayData = $inputData;
        $linesMAPerMBH = array();
        $linesTAPerMBH = array();
        $linesMGBZPerMBH = array();
        $linesMGBPPerMBH = array();
        $linesWDSPerMBH = array();
        // $this->lowerCheckDatum = $checkDatum;


        $this->lowerCheckDatum = date('YmdHis',strtotime($checkDatum . '-' . $lowerCheckMargin . ' day'));
        // echo "\n" . 'lowerCheckDatum' . $lowerCheckMargin . ' = ' . $this->lowerCheckDatum . "\n";


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

            if ($line['typeBouwsteen']=='WDS')
                $linesWDSPerMBH[$line['mbhID']][]=$line;

        }

        $this->checkDatum = $checkDatum;
        // uasort($this->arrayData, array($this,'sorteerAntiChronologisch'));
        // echo '<pre>';
        $this->checkMedicatieAfspraken($linesMAPerMBH);
        $this->checkToedieningsAfspraken($linesTAPerMBH);
        $this->checkWDSAfspraken($linesWDSPerMBH);

        $this->checkMedicatieGebruik($linesMGBZPerMBH);
        $this->checkMedicatieGebruik($linesMGBPPerMBH);
        // echo '<pre>';
        // print_r($this->checkList);

        $actueleData = array();
        $historischeData = array();

        foreach ($this->checkList as $mbhID => $mbhData) {
            foreach ($mbhData as $typeBS) {
                foreach ($typeBS as $line) {
                    // echo "\n" . $line['typeBouwsteen'] . "\t" . $line['id'] . "\t" . ($line['status']??'');
                    if (($line['status']??'')=='huidig') {
                        $actueleData[$mbhID][$line['id']] = $line;
                    }
                    if (($line['status']??'')=='gestopt') {
                        $historischeData[$mbhID][$line['id']] = $line;
                    }
                    if (($line['status']??'')=='gestaakt') {
                        $historischeData[$mbhID][$line['id']] = $line;
                    }
                    if (($line['status']??'')=='toekomst') {
                        $this->toekomstData[$mbhID][$line['typeBouwsteen']][$line['id']] = $line;
                    }
                }
            }
        }

        // print_r($this->checkList);
        $toekomstData = array();
        foreach ($this->toekomstData as $mbhID=>$bsData) {
            // $toekomstData[$mbhID] = array();
            foreach ($bsData as $typeBS => $data) {
                // $toekomstData[$mbhID]=array();
                foreach ($data as $id=>$line) {
                    $toekomstData[$mbhID][$id] = $line;
                }
            }
        }

        return array(
            'Actuele Medicatie'=>$actueleData,
            'Toekomstige Medicatie'=>$toekomstData,
            'Historische Medicatie'=>$historischeData,
            'skipList' => $this->skipList,
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
            $this->checkMedicatieAfsprakenPerMBH($mbhID,$lines);
        }

        // print_r($this->gestaakteMedicatie);
    }

    private function checkMedicatieAfsprakenPerMBH($mbhID,$lines) {

        uasort($lines, array($this,'sorteerAntiChronologisch'));

        //Bepaal de ondergrens tot wanneer moet worden doorge-itereerd.
        $checkAfspraakDatum = $this->getEersteAfspraakDatumMas($lines);

        $this->lijstCheckAfspraakDatums[$mbhID] = $checkAfspraakDatum;

        // echo "\ncheckAfspraakDatum $checkAfspraakDatum";

        // echo '<pre>';
        // print_r($lines);
        // echo '</pre>';
        foreach ($lines as $line) {

            // $this->log( "\n<br/>checking line <strong>" . $line['simpelID'] . '</strong>');


            if ($result = $this->checkM1($line)=='Y') {
                //heeft de MA een Annuleer vlag
                $this->log( 'positive M1 ');
                continue;
            }

            if ($result = $this->checkM2($line)=='Y') {
                // Staat MA als referentie op geannuleerde  checklist
                $this->log( 'positive M2 ');
                continue;
            }



            if ($result = $this->checkM3($line)=='Y') {
                    //Heeft stop-datum in verleden
                //We kunnen oudere MA's in deze MBH-ID negeren

                // $this->log('positive M3 ');
                if ($line['stop'] <= $this->lowerCheckDatum) {
                    return;
                }
            }

            if ($result = $this->checkM4($line)=="Y") {
                //StartDatum/tijd In toekomst
                // $this->log('positive M4 ');
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
        $status = '';

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            // De regelstaat in de gestaakte medicatielijst (dus is gestaakt door een nieuwere MA)
            // Dat betekent: niet actueel, maar ook niet historisch, dus uit de functie zonder verdere actie.
            $result = "Y";
            return $result;
        }

        if ($line['stop'] <= $this->checkDatum) {
            // Zet MA op Checklist. Oudere MA's kunnen genegeerd worden.
            $status = 'gestopt';
            $result = 'Y';


            if ($line['stopCode']!='') {
                //Er is maar 1 relatie
                // print_r($line['relaties']);
                $referentie = $line['relaties'][0]['ID']??'';
                $referentieType = $line['relaties'][0]['soortRelatieMemoCode']??'';
                if ($referentieType == 'MA') {
                    $this->gestaakteMedicatie[$line['mbhID']][$referentie]['MA']=
                        array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);
                }

                if ($line['stopCode']=='O') {
                    //het is een onderbroken medicatie.

                    if (!isset($this->checkList[$line['mbhID']])) {
                        $line['status'] = 'huidig';
                        $line['check'] = 'M3';
                        $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
                    }
                    return 'Y';
                }

            }

        }


        if ($result == "Y") {
            if (!isset($this->checkList[$line['mbhID']])) {
                $line['status'] = $status;
                $line['check'] = 'M3';
                $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
            }
        }


        return $result;
    }

    private function CheckM4($line) {
        //StartDatum/tijd In toekomst
        // $this->log( "Check M4 " . $line['simpelID']);
        // echo "\n" . $line['start'];
        // echo "\n" . $this->checkDatum;
        $result = 'N';

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            // De regelstaat in de gestaakte medicatielijst (dus is gestaakt door een nieuwere MA)
            // Dat betekent: niet actueel, maar ook niet historisch, dus uit de functie zonder verdere actie.
            $result = "Y";
            return $result;
        }

        // Of wanneer de ma die is gestopt al is gestopt

        $referentie = $line['relaties'][0]['ID']??'';
        $referentieType = $line['relaties'][0]['soortRelatieMemoCode']??'';
        if (isset($this->gestaakteMedicatie[$line['mbhID']][$referentie])) {
            // De regelstaat in de gestaakte medicatielijst (dus is gestaakt door een nieuwere MA)
            // Dat betekent: niet actueel, maar ook niet historisch, dus uit de functie zonder verdere actie.
            $result = "Y";
            return $result;
        }

        if ($line['start'] >= $this->checkDatum) {
            // MA is toekomst
            $line['status'] = 'toekomst';
            $line['check'] = 'M4';
            //De toekomstdada moet niet op de algemene checklist, omdat ze de huidige status niet beinvloeden
            $this->toekomstData[$line['mbhID']]['MA'][$line['id']]= $line;
            $result = 'Y';
        } else {
            $line['status'] = 'huidig';
            $line['check'] = 'M4';
            $this->checkList[$line['mbhID']]['MA'][$line['id']] = $line;
        }


        //is het een stop?

        if ($line['stopCode']!='') {
            if ($referentieType=='MA') {
                $this->gestaakteMedicatie[$line['mbhID']][$referentie]['MA'] =
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);
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
            $this->checkToedieningsAfsprakenPerMbh($mbhID,$lines);
        }
    }

    private function checkToedieningsAfsprakenPerMbh($mbhID,$lines) {
        uasort($lines, array($this,'sorteerAntiChronologisch'));
        foreach ($lines as $line) {
            // echo 'checking line ' . $line['simpelID'] . "\n";
            $this->log( "\n<br/>checking line <strong>" . $line['simpelID'] . '</strong>');

            if ($result = $this->checkT0Stop($line)=='Y') {
                $this->log( 'positive T0Stop ' . $line['simpelID']);
                // (T10) Staat de gerefereerde TA op de checklist en is gestopt

                continue;

            }

            if ($result = $this->checkT0($line)=='Y') {
                //(T0) Staat de referentie MA op de checklist met status geannuleerd
                $this->log( 'positive T0 ' . $line['simpelID']);
                continue;
            }

            if ($result = $this->checkT1($line)=='Y') {
                //(T0) is de TA geannuleerd
                $this->log( 'positive T1 ' . $line['simpelID']);
                continue;
            }

            if ($result = $this->checkT2($line)=='Y') {
                //(T0) Staat de referentie TGA op de checklist met status geannuleerd
                $this->log( 'positive T2 ' . $line['simpelID']);
                continue;
            }



            if ($result = $this->checkT3($line)=='Y') {
                // Staat de MBH-ID op de checklist van MA's
                $this->log( 'positive T3 ' . $line['simpelID']);
                if ($result = $this->checkT4($line)=='Y') {
                    // /Staat de gerefereerde MA op de checklist?
                    $this->log( 'positive T4 ' . $line['simpelID']);

                    if ($result = $this->checkT6($line)=='Y') {
                        $this->log( 'positive T6 ' . $line['simpelID']);
                        // Is de gerefereerde MA een stop MA of is gestopt door een stop-ma, met een stopdatum in het verleden
                        // TA is niet actueel
                        // this was a continue in the workflow, but
                        //maybe a return even, as all older TA's should be referencing an older MA too.
                        //there might be a parallel TA??
                        continue;

                    }

                } else {
                    if ($result = $this->checkT5($line)=='Y') {
                        $this->log( 'positive T5 ' . $line['simpelID']);
                        // Is de TA afspraak datum eerder dan de afspraak datum van de oudste MA in de checklist
                        // TA is niet actueel
                        continue;
                    }
                }
            }
            if ($result = $this->checkT7($line)=='Y') {
                // (T7) is de TA een STOP-TA
                $this->log( 'positive T7 ' . $line['simpelID']);
                if ($result = $this->checkT8($line)=='Y') {
                    $this->log( 'positive T8 ' . $line['simpelID']);
                    // (T8) is stopdatumtijd in het verleden
                    continue;

                } else {
                    if ($result = $this->checkT9($line)=='Y') {
                        $this->log( 'positive T9 ' . $line['simpelID']);
                        // (T9) is startdatumtijd in de toekomst
                    } else {
                        // TA is actueel Zet TA op checklist stop-ta-actueel
                    }
                }

                continue;

            }

            if ($result = $this->checkT11($line)=='Y') {
                $this->log( 'positive T11 ' . $line['simpelID']);
                // (T11) Is de gerefereerde MA een gestopte MA met stopdatum in de toekomst
                if ($result = $this->checkT15($line)=='Y') {
                    $this->log( 'positive T15 ' . $line['simpelID']);
                    // (T15) Is startdatum TA in de toekomst
                    continue;
                } else {

                }
                continue;

            }
            if ($result = $this->checkT12($line)=='Y') {
                // (T12) is de startdatumtijd in de toekomst
                $this->log( 'positive T12 ' . $line['simpelID']);

                if ($result = $this->checkT13($line)=='Y') {
                    $this->log( 'positive T13 ' . $line['simpelID']);
                    // (T13) Staat de TA op de checklist STOP-TA-TOEKOMST
                    continue;

                }

                continue;

            }

            if ($result = $this->checkT14($line)=='Y') {
                $this->log( 'positive T14 ' . $line['simpelID']);
                // (T14) Is stopdatumtijd TA in het verleden
                continue;

            }

                // (T15) Is startdatum TA in de toekomst

            if (!isset($this->checkList[$line['mbhID']]['TA'][$line['id']])) {
                echo 'TA not set anywhere, so probably current? ' . $line['simpelID'];
                $line['status'] = 'huidig';
                $line['check'] = 'T14+';
                $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
            }
        }


    }

    private function checkT0Stop($line) {
        //The TA is not a stop-TA
        // (T10) is the referenced TA on the stopchecklist
        $this->log( "Check T10 " . $line['simpelID']);

        $result = 'N';

        $refTA = $this->getReferentie($line,'TA');
        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            //the TA has been stopped
            if ($refTA)
                $this->gestopteLijst[$line['mbhID']]['TA'][$refTA]['TA'] = $line;
            $this->skipList[$line['mbhID']][$line['id']]['check'] = 'T0Stop.1';
            return 'Y';
            //Unfortunately we can't stop, as there might be a parallel TA
        }
        // print_r($this->gestopteLijst);
        $refTA = $this->getReferentie($line,'TA');
        if (isset($this->gestopteLijst[$line['mbhID']]['TA'][$line['id']])) {
            //the TA has been stopped
            if ($refTA) {
                //This will result in all other TA in the same train to be skipped
                $this->gestopteLijst[$line['mbhID']]['TA'][$refTA]['TA'] = $line;
            }
            $this->skipList[$line['mbhID']][$line['id']]['check'] = 'T0Stop.2';
            return 'Y';
        }

        if (isset($this->gestopteLijst[$line['mbhID']]['TA'][$refTA])) {
            // echo "\n" . '$refTA' . $refTA;
            //the TA has been stopped
            // $this->gestopteLijst[$line['mbhID']]['TA'][$refTA]['TA'] = $line;
            $this->skipList[$line['mbhID']][$line['id']]['check'] = 'T0Stop.3';
            return 'Y';
        }

        $refMA = $this->getReferentie($line,'MA');
        if ($refMA) {
            if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA])) {
                //the TA has been stopped
                if ($refTA)
                    $this->gestopteLijst[$line['mbhID']]['TA'][$refTA]['MA'] = $line;
                $this->skipList[$line['mbhID']][$line['id']]['check'] = 'T0Stop.4';
                return 'Y';
                //Unfortunately we can't stop, as there might be a parallel TA
            }
        }

        return $result;
    }

    private function checkT0($line) {

         //heeft de MA een Annuleer vlag
         //(T0) Staat de referentie MA op de checklist met status geannuleerd
         $this->log( "Check T0 " . $line['simpelID']);
         $result = 'N';
        //  $refMA = $this->getReferentie($line,'MA');
         foreach ($line['relaties'] as $relatie) {
            if ($relatie['soortRelatieMemoCode']=='MA') {
                $refMA = $relatie['ID'];
                if (isset($this->annuleerLijst[$line['mbhID']][$refMA])) {
                    $this->annuleerLijst[$line['mbhID']][$line['id']]=$line['id'];
                }
            }
         }


         return $result;

    }

    private function checkT1($line) {

        //heeft de TA een Annuleer vlag
        //(T1) Heeft de TA zelf een annuleer vlag
        $this->log( "Check T1 " . $line['simpelID']);
        $result = 'N';

        if ($line['stopCode']=='G') {
            $result = 'Y';
            $geannuleerdeID = $line['relaties'][0]['ID']??'';
            if ($geannuleerdeID)
                $this->annuleerLijst[$line['mbhID']][$geannuleerdeID]=$line['id'];
        }

        return $result;

   }

    private function checkT2($line) {

        // Staat referentie TA op geannuleerde ta checklist

        $this->log( "Check T2 " . $line['simpelID']);
        $result = 'N';
        foreach ($line['relaties'] as $relatie) {
        if ($relatie['soortRelatieMemoCode']=='TA') {
            $refTA = $relatie['ID'];
            if (isset($this->annuleerLijst[$line['mbhID']][$refTA])) {
                $this->annuleerLijst[$line['mbhID']][$line['id']]=$line['id'];
            }
        }
        }


        return $result;

    }

    private function checkT3($line) {

        // (T3) Staat de MBH-ID op de checklist van MA’s
        $this->log( "Check T3 " . $line['simpelID']);
        $result = 'N';

        if (isset($this->checkList[$line['mbhID']]['MA'])) {
            //Er is een MA geregistreed met deze mbhID
            $result = 'Y';
        }

        if (isset($this->toekomstData[$line['mbhID']]['MA'])) {
            //Er is een MA geregistreed met deze mbhID in de toekomst
            $result = 'Y';
        }

        return $result;
    }

    private function checkT4($line) {
        //(T4) Staat de gerefereerde MA op de checklist.
        $result = 'N';
        // print_r($this->checkList);

        $this->log( "Check T4 " . $line['simpelID']);
        foreach ($line['relaties'] as $relatie) {
            // print_r($relatie);
            if ($relatie['soortRelatieMemoCode']=='MA') {
                $refMA = $relatie['ID'];
                if (isset($this->checkList[$line['mbhID']]['MA'][$refMA])) {
                    $result = 'Y';
                }
                if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA])) {
                    $result = 'Y';
                }

            }
         }



        return $result;
    }

    private function checkT5($line) {
        //Situatie: Vanuit T4: Er is geen referentie naar een MA die op de checklist staat, maar wel dezelfde MBH-ID

        //(T5) Is de afspraak datum eerder dan de afspraak datum van de laatste MA in de checklist
        $result = 'N';
        // echo '<pre>';

        $this->log( "Check T5 " . $line['simpelID']);

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
            $line['status']='gestopt';
            $line['check'] = 'T5';
            $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
        }

        //wat is de laatste MA in de checklist?
        //Zie ook dossier met tussentijdse aanpassing

        return $result;
    }
    private function checkT6($line) {
        // (T6) Is de gerefereerde MA een (STOP-MA of is gestopt door een stop-ma) en de stop-datum van de STOP-MA in het verleden

        $this->log( "Check T6 " . $line['simpelID']);
        $result = 'N';
        $refMA = $this->getReferentie($line,'MA');
        //De MA staat op de checklist
        //Is de refMA een stop-ma?
        if (isset($this->checkList[$line['mbhID']]['MA'][$refMA])) {
            $maLine = $this->checkList[$line['mbhID']]['MA'][$refMA];

            if ($maLine['stopCode']!='' && $maLine['stopCode']!='G') {
                //Alleen de gestopte medicatie is interessant, aangezien de geannulleerde al is gepasseerd
                //en de gepauzeerde moeten apart worden behandeld
                if ($maLine['stop']<$this->checkDatum) {
                    if ($maLine['stopCode']=='G') {
                        $line['status']='gestopt';
                        $line['check'] = 'T6.1';
                    }
                    if ($maLine['stopCode']=='O') {
                        $line['status']='huidig';
                        $line['check'] = 'T6.2';
                    }
                    $result = 'Y';
                    $this->gestaakteMedicatie[$line['mbhID']][$line['id']]['MA']=
                        array('stopBSId'=>$maLine['id'],'stopDT'=>$maLine['stop']);
                    $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
                }
            }
        }

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA'])) {
            //de MA waar de TA naar verwijst is gestaakt.
            $info=$this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA'];

            if ($info['stopDT'] < $this->checkDatum) {
                $line['status'] = 'gestopt';
                $line['check'] = 'T6.3';
                $result = 'Y';
                $stopMA = $info['stopBSId'];
                $this->gestaakteMedicatie[$line['mbhID']][$line['id']]['MA']=$info;
                $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
            }
        }

        return $result;
    }

    private function checkT7($line) {
        // (T7) is de TA een STOP-TA
        $this->log( "Check T7 " . $line['simpelID']);
        $result = 'N';
        if ($line['stopCode']!='') {
            return 'Y';
        }

        return $result;
    }

    private function checkT8($line) {
        // The TA is a stop TA
        // (T8) is stop date/time in the past
        $this->log( "Check T8 " . $line['simpelID']);
        $result = 'N';
        if ($line['stop'] <= $this->checkDatum) {
            $refTA = $this->getReferentie($line,'TA');
            $this->gestaakteMedicatie[$line['mbhID']][$refTA]['TA']=
            array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);

            // if (empty($this->checkList[$line['mbhID']]['TA'][$refTA])) {
            //     //there is no previous TA refering the refTA
                $line['status']='gestopt';
                $line['check'] = 'T8';
                $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
                // if ($refTA)
                //     $this->checkList[$line['mbhID']]['TA'][$refTA] = $line;
            // }
            return 'Y';
        }
        return $result;
    }

    private function checkT9($line) {
        // The TA is a stop TA
        // (T9) is startDT in the future (Then it a shortening of a future TA, not a cancel!)
        $this->log( "Check T9 " . $line['simpelID']);
        $result = 'N';

        $refTA = $this->getReferentie($line,'TA');

        if ($line['start'] >= $this->checkDatum) {
            $line['status'] = 'toekomst';
            $line['check'] = 'T9';
            $result = 'Y';

            $this->toekomstData[$line['mbhID']]['TA'][$line['id']] = $line;

            if ($refTA)
                $this->gestaakteMedicatie[$line['mbhID']][$refTA]['TA']=
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);
            return $result;
        }

        //The TA is current, but it's also a stop TA
        $line['status'] = 'huidig';
        $line['check'] = 'T9';
        $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
        if ($refTA)
            $this->gestaakteMedicatie[$line['mbhID']][$refTA]['TA']=
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);

        return $result;
    }



    private function checkT11($line) {
        // (T11) Is de gerefereerde MA een gestopte MA met stopdatum in de toekomst
        $this->log( "Check T11 " . $line['simpelID']);
        $result = 'N';
        $refMA = $this->getReferentie($line,'MA');
        if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA'])) {
            //de MA waar de TA naar verwijst is gestaakt.
            $info=$this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA'];

            if ($info['stopDT'] > $this->checkDatum) {
                $result = 'Y';
            }
        }
        return $result;
    }

    private function checkT12($line) {
        // (T12) is de startdatumtijd in de toekomst
        $this->log( "Check T12 " . $line['simpelID']);
        $result = 'N';
        if ($line['start'] >= $this->checkDatum) {
            return 'Y';
        }

        return $result;
    }

    private function checkT13($line) {
        //the startdt is in the future
        // (T13) has the TA been stopped by a future TA
        $this->log( "Check T13 " . $line['simpelID']);
        $result = 'N';

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            //There is a newer TA, that should be shown
            //just ignore this ta
            return 'Y';
        } else {
            $line['status']='toekomst';
            $this->toekomstData[$line['mbhID']]['TA'][$line['id']] = $line;
            // $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
        }
        return $result;
    }

    private function checkT14($line) {
        // (T14) Is stopdatumtijd TA in het verleden
        $this->log( "Check T14 " . $line['simpelID']);
        $result = 'N';

        if ($line['stop'] <= $this->checkDatum) {
            $line['status'] = 'gestopt';
            $line['check'] = 'T14.1';
            $result = 'Y';

        } else {
            $line['status'] = 'huidig';
            $line['check'] = 'T14.2';
        }

        $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
        $refTA = $this->getReferentie($line,'TA');
        $this->gestopteLijst[$line['mbhID']]['TA'][$refTA]['TA'] = $line;

        // print_r($line);
        // print_r($this->checkList);

        return $result;
    }

    private function checkT15($line) {
        // (T14) Is stopdatumtijd TA in het verleden
        $this->log( "Check T15 " . $line['simpelID']);
        $result = 'N';
        $refMA = $this->getReferentie($line,'MA');

        if ($line['start'] >= $this->checkDatum) {
            //A future.. leave it be until it gets current
            $line['status']='toekomst';
            $line['check'] = 'T15';
            $this->toekomstData[$line['mbhID']]['TA'][$line['id']] = $line;

        } else {
            $line['status']='huidig';
            $line['check'] = 'T15';
            $this->checkList[$line['mbhID']]['TA'][$line['id']] = $line;
        }


        return $result;
    }





 /*
    ****************************************************************
    ***** consolidatie Medicatie gebruik ***************************
    ****************************************************************
    */

    private function checkMedicatieGebruik($linesMGBPerMBH) {
        foreach ($linesMGBPerMBH as $mbhID => $lines) {
            $this->checkMedicatieGebruikMBH($lines);
        }
    }

    private function checkMedicatieGebruikMBH($lines) {
        uasort($lines, array($this,'sorteerAntiChronologisch'));
        foreach ($lines as $line) {
            if ($result = $this->checkMG1($line)=='Y') {
                //(T0) Staat de referentie MA op de checklist met status geannuleerd
                // $this->log( 'positive MG1 ');

                if ($result = $this->checkMG2($line)=='Y') {
                    //(T0) Staat de referentie MA op de checklist met status geannuleerd
                    // $this->log( 'positive MG1 ');

                    continue;
                } else {
                    if ($result = $this->checkMG6($line)=='Y') {
                        //(T0) Staat de referentie MA op de checklist met status geannuleerd
                        // $this->log( 'positive MG1 ');
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
                // $this->log( 'positive MG1 ');

                continue;
            }
            if ($result = $this->checkMG4($line)=='Y') {
                //Heeft stop-datum in verleden
                // $this->log( 'positive MG1 ');

                continue;
            }
            if ($result = $this->checkMG5($line)=='Y') {
                //(T0) Staat de referentie MA op de checklist met status geannuleerd
                // $this->log( 'positive MG1 ');

                continue;
            }
        }
    }

    private function checkMG1($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // GEEN IDEE WAAROM!!
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';

        return $result;
    }

    private function checkMG2($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';

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
                            $line['check'] = 'MG3';
                            return 'Y';
                        }
                    }
                }
            }
        }

        $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;


        return $result;
    }

    private function checkMG4($line) {
        //Heeft stop-datum in verleden
        // $this->log( "Check MG1 " . $line['simpelID']);
        $bouwsteenType = $line['typeBouwsteen'];
        $result = 'N';
        if ($line['stop'] <= $this->checkDatum) {
            $result = 'Y';
            $line['status'] = 'gestopt';
            $line['check'] = 'MG4';
        }

        $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;


        return $result;
    }

    private function checkMG5($line) {
        // StartDatum/tijd In toekomst
        // $this->log( "Check MG1 " . $line['simpelID']);
        $bouwsteenType = $line['typeBouwsteen'];
        $result = 'N';
        if ($line['start'] >= $this->checkDatum) {
            // MGB is toekomst
            $line['status'] = 'toekomst';
            $line['check'] = 'MG5';
            // $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;
            $this->toekomstData[$line['mbhID']]['TA'][$line['id']] = $line;
            $result = 'Y';

        } else {
            $line['status'] = 'huidig';
            $line['check'] = 'MG5';
            $this->checkList[$line['mbhID']][$bouwsteenType][$line['id']] = $line;
        }


        return $result;
    }

    private function checkMG6($line) {
        // (MG 1) Heeft de MGB een verwijzing naar de MA/TA
        // $this->log( "Check MG1 " . $line['simpelID']);
        $result = 'N';

        return $result;
    }


 /*
    ****************************************************************
    ***** consolidatie Wisselend Doseerschema *************************
    ****************************************************************
    */

    private function checkWDSAfspraken($linesWDSPerMBH) {
        foreach ($linesWDSPerMBH as $mbhID => $lines) {
            $this->checkWDSAfsprakenPerMBH($mbhID,$lines);
        }
    }

    private function checkWDSAfsprakenPerMBH($mbhID,$lines) {
        uasort($lines, array($this,'sorteerAntiChronologisch'));

        $checkAfspraakDatum = $this->lijstCheckAfspraakDatums[$mbhID]??'';

        // echo "\ncheckAfspraakDatum $checkAfspraakDatum";

        // echo '<pre>';
        // print_r($lines);
        // echo '</pre>';
        foreach ($lines as $line) {

            $this->log( "\n<br/>checking line <strong>" . $line['simpelID'] . '</strong>');
            $refMA = '';

            foreach ($line['relaties'] as $relatie) {
                if ($relatie['soortRelatieMemoCode']=='MA') {
                    $refMA = $relatie['ID'];
                }
                }

            if ($result = $this->checkW0($line,$refMA)=='Y') {
                // Staat de referentie MA op de ckecklist met status geannuleerd
                $this->log( 'positive WDS1 ');
                continue;
            }

            if ($result = $this->checkW3($line)=='Y') {
                // Staat de MBH-ID op de checklist van MA's
                $this->log( 'positive WDS3 ');
                if ($result = $this->checkW4($line,$refMA)=='Y') {
                    // Staat de gerefereerde MA op de checklist?
                    $this->log( 'positive WDS4 ');
                    if ($result = $this->checkW6($line,$refMA)=='Y') {
                        // Is de gerefereerde MA een stop MA of is gestopt door een stop-ma, met een stopdatumin het verleden
                        $this->log( 'positive WDS6 ');
                        //Oudere WDS'n hoeven niet gecontroleerd te worden
                        return;
                    }
                    //  else naar w7

                } else {
                    if ($result = $this->checkW5($line)=='Y') {
                        // Is de gerefereerde MA een stop MA of is gestopt door een stop-ma, met een stopdatum in het verleden
                        $this->log( 'positive WDS5 ');
                        return;
                    }
                    //  else naar w7
                }

            }

            if ($result = $this->checkW7($line)=='Y') {
                //is de WDS een STOP-WDS
                $this->log('positive WDS 7');
                if ($result = $this->checkW8($line)=='Y') {
                    // Is stopdatum/tijd in verleden
                    $this->log( 'positive WDS8 ');
                    if ($line['stop'] <= $this->lowerCheckDatum) {
                        return;
                    }
                } else {
                    if ($result = $this->checkW9($line)=='Y') {
                        // Is startdatum in de toekomst
                        $this->log( 'positive WDS9 ');
                        continue;
                    } else {
                        //als de wds huidig, dan mag de rest worden overgeslagen (slechts 1 wds geldig op elke moment)
                        //de check houdt rekening met de lowercheckdatum, als de stop voor deze checkdatum ligt,
                        // dan hoeft de WDS niet verder bekeken worden.
                        if ($line['stop'] <= $this->lowerCheckDatum) {
                            return;
                        }
                    }
                }
                continue;
            }


            if ($result = $this->checkW10($line)=="Y") {
                //StartDatum/tijd In toekomst
                // Staat de gerefereerde WDS op de STOP checklist

                $this->log('positive WDS10 ');
                continue;
            }

            if ($result = $this->checkW11($line)=='Y') {
                // Is de gerefereerde MA gestopt door een MA met stopdatum in de toekomst
                $this->log( 'positive WDS11 ');
                if ($result = $this->checkW15($line)=='Y') {
                    // Is startdatum WDS in de toekomst
                    $this->log( 'positive WDS15 ');
                    continue;
                }
                continue;
            }

            if ($result = $this->checkW12($line)=='Y') {
                // Is startdatumtijd WDS toekomst
                $this->log( 'positive WDS12 ');
                if ($result = $this->checkW13($line)=='Y') {
                    // Staat de WDS op de checklist STOP-WDS-TOEKOMST
                    $this->log( 'positive WDS13 ');
                    continue;
                }
                continue;
            }

            if ($result = $this->checkW14($line)=='Y') {
                // Is stopdatumtijd WDS in het verleden
                $this->log( 'positive WDS14 ');
            }

            //Er is een huidige WDS, vorige WDS'en kunnen worden genegeerd als de stopdatum voor de lowercheckdatum is.
            if ($line['stop'] <= $this->lowerCheckDatum) {
                return;
            }

        }
    }


    private function checkW0($line,$refMA) {
        $this->log( "Check W0 " . $line['simpelID']);
        // Staat de referentie MA op de ckecklist met status geannuleerd
        $result = 'N';

        if (isset($this->annuleerLijst[$line['mbhID']][$refMA])) {
            //Zet de WDS regel zelf op de annuleerlijst
            $this->annuleerLijst[$line['mbhID']][$line['id']]=$line['id'];
            return 'Y';
        }


        return $result;
    }

    private function checkW3($line) {
        $this->log( "Check W3 " . $line['simpelID']);
        $result = 'N';
        // Staat de MBH-ID op de checklist van MA's
        if (isset($this->checkList[$line['mbhID']]['MA']))
            $result = 'Y';

        return $result;
    }

    private function checkW4($line,$refMA) {
        $this->log( "Check W4 " . $line['simpelID']);
        $result = 'N';
        // Staat de gerefereerde MA op de checklist?
        if (isset($this->checkList[$line['mbhID']]['MA'][$refMA]))
            return 'Y';



        return $result;
    }

    private function checkW5($line) {
        $this->log( "Check W5 " . $line['simpelID']);
        // Is de WDS afspraak datum eerder dan de afspraak datum van de oudste MA in de checklist
        // ?? Kan dit niet gewoon nieuwste MA zijn in de checklist..
        // Kennelijk is er een nieuwere MA dan de wds
        // print_r($this->checkList);
        $oudsteMAaAfspraakdatum = '';
        foreach ($this->checkList[$line['mbhID']]['MA'] as $maInfo) {
            $maAfspraakDatum = $maInfo['afspraakDatumTijd'];
            if ($oudsteMAaAfspraakdatum=='') {
                $oudsteMAaAfspraakdatum = $maAfspraakDatum;

            }
            if ($oudsteMAaAfspraakdatum > $maAfspraakDatum) {
                $oudsteMAaAfspraakdatum = $maAfspraakDatum;
            }
        }
        if ($line['afspraakDatumTijd'] < $oudsteMAaAfspraakdatum) {
            return 'Y';
        }
        $result = 'N';

        return $result;
    }

    private function checkW6($line,$refMA) {
        $this->log( "Check W6 " . $line['simpelID']);
        $result = 'N';
        // Is de gerefereerde MA een stop MA of is gestopt door een stop-ma, met een stopdatumin het verleden
        $maLine = $this->checkList[$line['mbhID']]['MA'][$refMA];
        if ($maLine['status']=='gestopt') {
            return 'Y';
        }

        if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA])) {
            return 'Y';
        }


        return $result;
    }
    private function checkW7($line) {
        $this->log( "Check W7 " . $line['simpelID']);
        //is de WDS een STOP-WDS
        $result = 'N';
        //nb ook een pause moet worden beschouwd als STOP, want er mag niet op toegediend worden
        if ($line['stopCode']!='') {
            $result = 'Y';

        }

        return $result;
    }

    private function checkW8($line) {
        $this->log( "Check W8 " . $line['simpelID']);
        //het is een stop WDS
        $result = 'N';
        if ($line['stop'] <= $this->checkDatum) {
            // Zet MA op Checklist. Oudere WDS'en kunnen genegeerd worden.
            $refWDS = '';
            foreach ($line['relaties'] as $relatie) {
                if ($relatie['soortRelatieMemoCode']=='WDS') {
                    $refWDS = $relatie['ID'];
                }
             }

            $line['status'] = 'gestopt';
            $line['check'] = 'W8';
            $result = 'Y';
            $this->checkList[$line['mbhID']]['WDS'][$line['id']] = $line;
            $refWDS = $this->getReferentie($line,'WDS');
            if ($refWDS)
                $this->gestaakteMedicatie[$line['mbhID']][$refWDS]['WDS']=
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);
        }

        return $result;
    }

    private function checkW9($line) {
        $this->log( "Check W9 " . $line['simpelID']);
        // Is startdatum in de toekomst
        $result = 'N';
        $refWDS = $this->getReferentie($line,'WDS');
        if ($line['start'] >= $this->checkDatum) {
            $line['status'] = 'toekomst';
            $line['check'] = 'W9';
            $result = 'Y';
            $this->toekomstData[$line['mbhID']]['WDS'][$line['id']] = $line;
            // $this->checkList[$line['mbhID']]['WDS'][$line['id']] = $line;

            if ($refWDS)
                $this->gestaakteMedicatie[$line['mbhID']][$refWDS]['WDS']=
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);
            return $result;
        }

        //het is een huidige (maar ook een stop-wds)
        $line['status'] = 'huidig';
        $line['check'] = 'W9';
        $this->checkList[$line['mbhID']]['WDS'][$line['id']] = $line;
        if ($refWDS)
            $this->gestaakteMedicatie[$line['mbhID']][$refWDS]['WDS']=
                array('stopBSId'=>$line['id'],'stopDT'=>$line['stop']);


        return $result;
    }

    private function checkW10($line) {
        $this->log( "Check W10 " . $line['simpelID']);
        // het is geen stop-wds
        // Staat de gerefereerde WDS op de STOP checklist
        // print_r($this->gestaakteMedicatie[$line['mbhID']]);
        $result = 'N';
        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            return 'Y';
        }

        return $result;
    }

    private function checkW11($line) {
        $this->log( "Check W11 " . $line['simpelID']);
        // Is de gerefereerde MA gestopt door een MA met stopdatum in de toekomst
        $result = 'N';
        $refMA = $this->getReferentie($line,'MA');
        if ($refMA) {
            if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA])) {
                //Zoek de stop-ma
                $stopDT = $this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA']['stopDT'];
                if ($stopDT > $this->checkDatum) {
                    return 'Y';
                }

            }
        }

        return $result;
    }

    private function checkW12($line) {
        $this->log( "Check W12 " . $line['simpelID']);
        // Is startdatumtijd WDS toekomst
        $result = 'N';
        if ($line['start']>$this->checkDatum) {
            return 'Y';
        }


        return $result;
    }

    private function checkW13($line) {
        $this->log( "Check W13 " . $line['simpelID']);
        // Staat de WDS op de checklist STOP-WDS-TOEKOMST
        // Ik weet niet of we hier ooit komen??
        if (isset($this->gestaakteMedicatie[$line['mbhID']][$line['id']])) {
            // $line['status']='toekomst';
            return 'Y';
        } else {
            $line['status']='toekomst';
            $line['check'] = 'W13';
            $this->toekomstData[$line['mbhID']]['WDS'][$line['id']] = $line;
            // $this->checkList[$line['mbhID']]['WDS'][$line['id']] = $line;
        }
        $result = 'N';

        return $result;
    }

    private function checkW14($line) {
        $this->log( "Check W14 " . $line['simpelID']);
        $result = 'N';
        if ($line['stop'] <= $this->checkDatum) {
            $line['status']='gestopt';
            $line['check'] = 'W14';
            $result = 'Y';
        } else {
            $line['status']='huidig';
            $line['check'] = 'W14';
        }
        $this->checkList[$line['mbhID']]['WDS'][$line['id']] = $line;

        return $result;
    }

    private function checkW15($line) {
        $this->log( "Check W15 " . $line['simpelID']);
        $result = 'N';
        if ($line['start'] >= $this->checkDatum) {
            $line['status']='toekomst';
            $line['check'] = 'W15';
            $result = 'Y';
        } else {
            $line['status']='huidig';
            $line['check'] = 'W15';
        }

        //we zetten de virtuele stopdatum, dat is de stopdatum van de
        $refMA = $this->getReferentie($line,'MA');
        if ($refMA) {
            if (isset($this->gestaakteMedicatie[$line['mbhID']][$refMA])) {
                $stopDT = $this->gestaakteMedicatie[$line['mbhID']][$refMA]['MA']['stopDT'];
                $line['virtuelestop']=$stopDT;
            }
        }


        return $result;
    }

    private function getReferentie($line,$typeBS) {
        $referentie = '';
        foreach ($line['relaties'] as $relatie) {
            if ($relatie['soortRelatieMemoCode']==$typeBS) {
                $referentie = $relatie['ID'];
            }
        }
        return $referentie;
    }

    private function getEersteAfspraakDatumMas($lines) {

        //Bepaal de eerste afspraakdatum tot wanneer de iteraties moeten worden uitgevoerd.
        //Dat is minimaal
        //  De afspraakdatum van de afspraak die als laatste wordt gestart
        //  of de lowercheckdatum die 'vandaag' is of in het geval van bijvoorbeeld het maken van een toedienlijst 'vandaag' - 24 uur

        $firstKey = array_key_first($lines);
        $firstLine = $lines[$firstKey];
        $checkAfspraakDatum = $firstLine['afspraakDatumTijd'];
        if ($this->lowerCheckDatum < $checkAfspraakDatum) {
            return $this->lowerCheckDatum;
        }
        return $checkAfspraakDatum;
    }


    private function sorteerAntiChronologisch($a,$b) {
        //Dit is een wijziging t.o.v. de specs!
        //Sorteer eerst op de startdatum, dan op afspraakdatum

        if ($a['mbhID'] < $b['mbhID'])
            return -1;
        if ($a['mbhID'] > $b['mbhID'])
            return 1;

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