<?php

class PrintDossier {

    private $fileType = '';
    private $templateIds=array();


    private $arrayData;
    private $patientData;
    private $dossierData;
    private $consolidatedData;
    private $parsedData = array();
    private $useAfspraakDatumTijdToSort = true;
    private $checkDatum;
    private $fileName;
    private $startMBH;
    private $stopMBH;
    private $chronisch;

    private $bouwsteenCodes = array();

    public function __construct($checkDatum,$fileName='',$fileType='') {
        $this->checkDatum = $checkDatum;
        $this->fileName = $fileName;
        $this->fileType = $fileType;
    }

    public function process($inputData) {
        $this->arrayData = $inputData;
        $this->printDossier($inputData);
    }

    public function printDossier($dossierData,$consolidatedData) {
        echo '<h2>' . date('d-M-Y',strtotime($this->checkDatum)) . '</h2>';
        $this->dossierData = $dossierData;
        $this->consolidatedData = $consolidatedData;
        $this->setFlagsOnDossierData();
        // echo $this->checkDatum;
        // print_r($dossierData);
        uasort($this->dossierData, array($this,'sorteerChronologisch'));
        // print_r($dossierData);
        $this->printConsolidatieLijst($this->dossierData,$this->consolidatedData);
        echo '<p>&nbsp;</p>';
        $this->printTotaalSet($this->dossierData);
        echo '<p>&nbsp;</p>';
        $this->printTimeLine($this->dossierData);
        echo '<p>&nbsp;</p>';

        // $this->printConsolidatieLijst($dossierData,$consolidatedData);
        echo '<pre>';
        print_r($this->consolidatedData);
        print_r($this->dossierData);
        echo '</pre>';
    }

    private function setFlagsOnDossierData() {

        foreach ($this->consolidatedData as $type=>$dataSet) {
            if ($type=='traceInfo')
                continue;
            if (!empty($dataSet)) {
                foreach ($dataSet as $mbhID => $data) {
                    // print_r($data);
                    foreach ($data as $id => $line) {
                        // echo $id;
                        if (isset($this->dossierData[$id]))
                            $this->dossierData[$id]['check'] = $line['check']??'AA';

                    }
                }
            }
        }
    }


    private function printTotaalSet($dossierData) {

        echo '<table class="CONS" border=1>';
        echo '<tr>';
            echo '<th></th><th>typeBouwsteen</th>';
            echo '<th>ID</th>';
            // echo '<th>Ref ID</th>';
            echo '<th>afspraakDatumTijd</th>';
            echo '<th>start</th>';
            echo '<th>stop</th>';
            echo '<th>geneesmiddel</th>';
            echo '<th>dosering</th>';
            echo '<th>Check</th>';
        echo '</tr>';

        $mbhid='';
        $this->startMBH = '99999999999999';
        $this->stopMBH = '';
        $this->chronisch = 0;
        foreach ($dossierData as $line) {
                if ($line['mbhID']!=$mbhid && $mbhid!='') {

                    echo '<tr><td>&nbsp;</th></tr>';
                }
                $start = $line['start']??'';
                $stop = $line['stop']??'';
                $afspraakDatumTijd = $line['afspraakDatumTijd'];

                if ($start != '' && $start < $this->startMBH)
                    $this->startMBH = $start;
                if ($afspraakDatumTijd < $this->startMBH)
                    $this->startMBH = $afspraakDatumTijd;

                if ($stop != '30991231000000' && $stop > $this->stopMBH)
                    $this->stopMBH = $stop;
                if ($afspraakDatumTijd > $this->stopMBH)
                    $this->stopMBH = $afspraakDatumTijd;

                if ($stop == '30991231000000') {
                    $this->chronisch =1;
                    $stop='';
                }


                $mbhid = $line['mbhID'];
                $afspraakDatumAfterRequestDatum=($afspraakDatumTijd > $this->checkDatum);
                // echo "\n" . $afspraakDatumTijd . ' ' . $this->checkDatum;
                // $referentie = '';
                // if (($line['stopCode']??'')!='') {

                //     $refID = $line['relaties'][0]['ID']??'';
                //     $refSimpelID = $dossierData[$refID]['simpelID']??'';
                //     $referentieType = $line['relaties'][0]['soortRelatieMemoCode']??'';
                //     $referentie = $referentieType . '&nbsp;' . $refSimpelID;

                // }

                echo sprintf('<tr class="%s">',($afspraakDatumAfterRequestDatum?'CONS_inactiveline':''));
                echo '<td>' . ($line['stopCode']??'') . '</td>';
                echo '<td>' . ($line['typeBouwsteen']!='MA'?'&nbsp;&nbsp;&nbsp;':'') . $line['typeBouwsteen'] . '</td>';
                echo '<td>' . $line['simpelID'] . '</td>';
                // echo '<td>' . $referentie . '</td>';

                echo '<td>' . date('Y-m-d H:i',strtotime($line['afspraakDatumTijd'])) . '</td>';
                echo '<td>' . ($start==''?'':date('Y-m-d H:i',strtotime($start))) . '</td>';
                echo '<td>' . ($stop==''?'':date('Y-m-d H:i',strtotime($stop))) . '</td>';

                echo '<td>' . ($line['geneesMiddel']['naam']??'') . '</td>';
                echo '<td>' . ($line['omschrijvingDosering']??'') . '</td>';
                echo '<td>' . ($line['check']??'') . '</td>';
                echo '</tr>';
        }
        echo '</table>';
    }

    public function printTimeLine($dossierData) {

        $startDisp = substr($this->startMBH,0,8);
        $stopDisp = substr($this->stopMBH,0,8);

        // echo "\n\n start: $startDisp stop: $stopDisp\n";


        $start_date = date_create($startDisp);

        $end_date = date_create($stopDisp);
        if ($this->chronisch)
            $end_date->add(new DateInterval("P3M"));
        else
            $end_date->add(new DateInterval("P3D"));
        $interval = new DateInterval('P1D');

        $date_range = new DatePeriod($start_date, $interval, $end_date, DatePeriod::INCLUDE_END_DATE);

        $dateArr = array();
        foreach ($date_range as $rangeDate) {
            $date = $rangeDate->format('Y-m-d');
            $dateXML = $rangeDate->format('Ymd');
            $dateArr[]=array('date'=>$date,'dateXML'=>$dateXML);
        }
        // print_r($dateArr);
        $numCols = count($dateArr);
        $mbhID='';
        $activeCheckDateSet = false;
        echo '<table class="CONS" border=0>';
        echo sprintf('<tr data-filetype="%s" data-file="%s"><td class="CONS_timedummy">&nbsp;</td>',$this->fileType,$this->fileName);
            foreach ($dateArr as $dateSet) {
                $activeCheckDate = $dateSet['dateXML']==substr($this->checkDatum,0,8);
                if ($activeCheckDate)
                    $activeCheckDateSet = $activeCheckDate;
                echo sprintf('<td class="CONS_time %s" data-date="%s">%s</td>',($activeCheckDate?'querydt':''),$dateSet['date'],($activeCheckDate?'V':''));
            }
            if (!$activeCheckDateSet) {
                echo '<td class="CONS_timedummy">&nbsp;</td><td class="CONS_timedummy">&nbsp;</td><td class="CONS_timedummy">&nbsp;</td><td class="CONS_timedummy">&nbsp;</td><td class="CONS_timedummy querydt">V</td>';
            }
        echo '</tr>';
        foreach ($dossierData as $line) {
            if ($line['typeBouwsteen']=='MVE')
                continue;
            // if ($line['typeBouwsteen']!='MA')
            //     continue;
            if ($mbhID != $line['mbhID'] && $mbhID != '')
                echo "</tr><td colspan='$numCols'>&nbsp;<hr/></td><tr>";
            $mbhID = $line['mbhID'];
            $dateAfspraakPrint = substr($line['afspraakDatumTijd'],0,8);
            // $dateStart = substr(($line['start']??''),0,8);
            // $dateStop = substr(($line['stop']??''),0,8);
            $dateAfspraak = $line['afspraakDatumTijd'];
            $dateStart = $line['start']??'';
            $dateStop = $line['stop']??'';


            // echo "<pre>\n";
            // print_r($line);
            // echo "\n</pre>";
            echo '<tr>';
            echo '<td>' . $line['typeBouwsteen'] . '&nbsp;' . $line['simpelID'] . '&nbsp;</td>';
            foreach ($dateArr as $dateSet) {

                $datePrint = $dateSet['date'];
                $dateXML = $dateSet['dateXML'] . '000000';

                $class='';
                $let = '';
                // echo "\n" . $dateXML . "\t" . $dateAfspraak;
                // echo "\n" . $dateSet['dateXML'] . "\t" . $dateAfspraakPrint;
                if ($dateSet['dateXML']==$dateAfspraakPrint) {
                    $class='CONS_afspraak ';
                    // $let = 'A';
                    // echo $class;
                }
                if ($dateXML == $dateStart) {
                    //truukje in display tijdbalk om geannuleerde afspraken te tonen..
                    //De start- en stopdatum zijn namelijk gelijk
                    $let = '';
                    if ($line['stopCode']=='G') {
                        $class .= (' CONS_' . $line['stopCode'] . $line['typeBouwsteen']);
                    }
                    if (!$line['beschikbaar'])
                        $class .= ' CONS_nietbeschikbaar';
                }

                if ($dateXML >= $dateStart && $dateXML < $dateStop) {

                    $class .= (' CONS_' . $line['stopCode'] . $line['typeBouwsteen']);
                    // echo $class;
                    // $let = 'W';

                    if (!$line['beschikbaar'])
                        $class .= ' CONS_nietbeschikbaar';
                }
                echo sprintf('<td class="%s">%s</td>',$class,$let);
            }
            // echo '<td>&nbsp;' . $line['typeBouwsteen'] . '&nbsp;' . $line['simpelID'] . '&nbsp;</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private function printConsolidatieLijst($dossierData,$consolidatedData) {

        // print_r($consolidatedData);
        $mbhid = '';
        echo '<table class="CONS">';
        echo '<tr>';
        echo '<th></th><th>BS</th>';
        echo '<th>ID</th>';
        echo '<th>Afspraak DatumTijd</th>';
        echo '<th>Start</th>';
        echo '<th>Stop</th>';
        echo '<th>Geneesmiddel</th>';
        echo '<th>Dosering</th>';
        echo '</tr>';
        foreach ($consolidatedData as $type=>$dataSet) {
            if ($type=='traceInfo')
                continue;
            if ($type=='skipList')
                continue;

            if (!empty($dataSet)) {
                echo '<tr><td colspan=7><strong>' . $type . '</strong></td></tr>';

                foreach ($dataSet as $mbhID => $data) {
                    echo '<tr><td>&nbsp;</th></tr>';
                    foreach ($data as $id => $line) {
                        $this->printConsolidatieLine($line);
                    }
                }
                echo '<tr><td colspan=7>&nbsp;</td></tr>';
            }

        }
        echo '</table>';
    }


    private function printConsolidatieLine($line) {

        $start = $line['start']??'';
        $stop = $line['stop']??'';

        if ($stop == '30991231000000') {
            $stop='';
        }

        // $afspraakDatumAfterRequestDatum=($afspraakDatumTijd > $this->checkDatum);
        // // echo "\n" . $afspraakDatumTijd . ' ' . $this->checkDatum;

        // echo sprintf('<tr class="%s">',($afspraakDatumAfterRequestDatum?'inactiveline':''));
        echo '<tr>';
        echo '<td>' . ($line['stopCode']??'') . '</td>';
        echo '<td>' . (($line['typeBouwsteen']??'')!='MA'?'&nbsp;&nbsp;&nbsp;':'') . ($line['typeBouwsteen']??'') . '</td>';
        echo '<td>' . ($line['simpelID']??'') . '</td>';

        echo '<td>' . date('Y-m-d H:i',strtotime(($line['afspraakDatumTijd']??''))) . '</td>';
        echo '<td>' . ($start==''?'':date('Y-m-d H:i',strtotime($start))) . '</td>';
        echo '<td>' . ($stop==''?'':date('Y-m-d H:i',strtotime($stop))) . '</td>';

        echo '<td>' . ($line['geneesMiddel']['naam']??'') . '</td>';
        echo '<td>' . ($line['omschrijvingDosering']??'') . '</td>';
        echo '</tr>';

    }

    private function sorteerChronologisch($a,$b) {

        if ($a['mbhID'] < $b['mbhID'])
            return -1;
        if ($a['mbhID'] > $b['mbhID'])
            return 1;

        if ($a['afspraakDatumTijd'] < $b['afspraakDatumTijd'])
            return -1;

        if ($a['afspraakDatumTijd'] > $b['afspraakDatumTijd'])
            return 1;

        if (($a['start']??'') < ($b['start']??''))
            return -1;
        if (($a['start']??'') > ($b['start']??''))
            return 1;

        if (($a['stop']??'') < ($b['stop']??''))
            return -1;
        if (($a['stop']??'') > ($b['stop']??''))
            return 1;

        return 0;

    }
}