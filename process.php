<?php
//    echo '<pre><code>';
//    print_r($_POST);
//    print_r($_FILES);
    include_once('consolidatie.php');
    include_once('printdossier.php');
    include_once('loadData.php');

    if (isset($_GET['userID'])) {
        $userID=$_GET['userID'];
    } elseif (isset($_POST['userID'])) {
        $userID=$_POST['userID'];
    } elseif (isset($_COOKIE['userID'])) {
        $userID=$_COOKIE['userID'];
    } else {
        $userID = uniqid();
    }
    setcookie('userID', $userID, time() + (86400 * 3000), "/"); // 86400 = 1 day


    $checkDatum = date('Ymd000000');
    if (!empty($_GET['checkDatum']))
        $checkDatum = date('Ymd000000',strtotime($_GET['checkDatum']));

    $filteredData = array();

    $fileHandler = new fileHandler();
    $fileHandler->run();
    $parsedData = $fileHandler->getParsedData();
    $fileType = $fileHandler->getFileType();
    $fileName = $fileHandler->getFileName();
    $rawXML = $fileHandler->getRawXML();

    foreach ($parsedData as $id=>$line) {
        $afspraakDatumTijd = $line['afspraakDatumTijd'];
        $beschikbaar = ($afspraakDatumTijd>$checkDatum?0:1);
        $parsedData[$id]['beschikbaar']=$beschikbaar;
        if ($beschikbaar)
            $filteredData[$id]=$line;
    }

    $consolidator = new ConsolidatieEngine();
    $consolidatedData = $consolidator->process($filteredData,$checkDatum);

    $printer = new PrintDossier($checkDatum,$fileName??'',$fileType);
    $printer->printDossier($parsedData,$consolidatedData);

    echo '<pre><code>';

    print_r($parsedData);
    // print_r($array);
    // echo htmlspecialchars($rawXML);

    echo '</code></pre>';



?>
