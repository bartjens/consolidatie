<?php

if (isset($_GET['newUserID'])) {
	$userID = $_GET['newUserID'];
} else{
	$userID = $_COOKIE['userID']??'';
}
if ($userID=='') {
	$userID = uniqid();

}
setcookie('userID', $userID, time() + (86400 * 3000), "/"); // 86400 = 1 day

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
    <title></title>
    <link rel="stylesheet" href="style.css" type="text/css" />

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script type="text/javascript">
		var fileName;
		var fileType;
		var userID;

		// fileType =
		//			'sxmlv3' -> system xml (github) v3
		// 			'parsedjson' -> system json (compact json from github)
		// 			'uxmlv3' -> useruploaded xml v3
		// 			'motooljson'; -> from MO tool

		var checkDatum;

        $(document).ready(function () {

            $('#inputFile').change(function () {
				fileName = $('#inputFile').val();
				fileType = 'sxmlv3';

				checkDatum = '<?php echo date('Y-m-d');?>'
				$('#jsonFile').val('');
				$('#userFile').val('');
		    	parseFile(0);
			});

			$('#jsonFile').change(function () {
				fileName = $('#jsonFile').val();
				checkDatum = '<?php echo date('Y-m-d');?>';
				fileType = 'parsedjson';
				$('#inputFile').val('');
				$('#userFile').val('');
		    	parseFile(0);
			});

			$('#userFile').change(function () {
				fileName = $('#userFile').val();
				checkDatum = '<?php echo date('Y-m-d');?>';
				fileType = 'uxmlv3';
				$('#inputFile').val('');
				$('#jsonFile').val('');
		    	parseFile(0);
			});

			$(document).on('change', '#userID', function () {


				// console.log(form_data);
				$.ajax({
					type: 'GET',
					url: 'index.php',
					data: {newUserID:$(this).val()},
					success: function (data) {
						location.reload();
					},
					error: function (xhr, status) {
						alert("Sorry, there was a problem!");
					},
					complete: function (xhr, status) {

					}
				});


			});


			$(document).on('click', '.CONS_time', function () {
				fileType = $(this).closest('tr').data('filetype');
				fileName = $(this).closest('tr').data('file');
				checkDatum = $(this).data('date');

		    	parseFile(0);
			});

			$(document).on('click', '#reload', function () {
		    	parseFile(0);
			});

			$(document).on('click', '#makejson', function () {
		    	parseFile(1);

			});


			$("#postfile").submit(function(event) {

				/* stop form from submitting normally */
				event.preventDefault();

				var form_data = new FormData($('#postfile')[0]);
				form_data.append('upload', 1);
				form_data.append('userID', $('#userID').val());

				// console.log(form_data);
				$.ajax({
					type: 'POST',
					url: 'process.php',
					data: form_data,
					processData: false,
					contentType: false,
					dataType: "html",
					success: function (data) {
						$('#result').html(data);
						reloadUserUploads();
						// location.reload();

					},
					error: function (xhr, status) {
						alert("Sorry, there was a problem!");
					},
					complete: function (xhr, status) {

					}
				});

			});




        });

var reloadUserUploads = function() {
	resultaatveld = 'useruploads';
	userID = $('#userID').val();

	var jqxhr =
	$.ajax({
		url: 'getuseruploads.php',
		type : "GET",
		data: {userID:userID}
	})

	.done (function(response) {
		$('#' + resultaatveld).html(response);
		if (makeJson==1) {
			location.reload();
		}

	})

	.fail(function(jqXHR, textStatus) {
	  console.log( "Request failed: " , textStatus );

	})
	return '';

}

var parseFile = function (makeJson) {


	resultaatveld = 'result';
	userID = $('#userID').val();

	var jqxhr =
	$.ajax({
		url: 'process.php',
		type : "GET",
		data: {fileName : fileName, fileType:fileType, checkDatum:checkDatum, makeJson:makeJson, userID:userID}
	})

	.done (function(response) {
		$('#' + resultaatveld).html(response);
		if (makeJson==1) {
			location.reload();
		}

	})

	.fail(function(jqXHR, textStatus) {
	  console.log( "Request failed: " , textStatus );

	})
	return '';
}



    </script>
</head>


<body>

</html>


<?php
    $xmlFiles = scandir('./data');
    foreach ($xmlFiles as $id=>$file) {
        if ($file=='.' || $file=='..')
            unset($xmlFiles[$id]);
    }


?>

<?php
    $jsonFiles = scandir('./simpledata');
    foreach ($jsonFiles as $id=>$file) {
        if ($file=='.' || $file=='..')
            unset($jsonFiles[$id]);
    }

	$userFiles = array();
	$userPath = './uploads/' . $userID;
	if (is_dir('./uploads/' . $userID)) {
		$userFiles = scandir('./uploads/' . $userID);
		foreach ($userFiles as $id=>$file) {
			if ($file=='.' || $file=='..')
				unset($userFiles[$id]);
		}
	}


?>
<form id='postfile'>
User: <input type='text' size='4' name='userID' id='userID' value = '<?php echo $userID;?>'>
XML source
<select id='inputFile' name='inputFile'>
	<option value=''>Select a File</option>
	<?php foreach ($xmlFiles as $fileName) : ?>
		<option value='<?php echo 'data/' . $fileName;?>'><?php echo $fileName;?></option>
	<?php endforeach ?>
</select>

&nbsp;&nbsp; Parsed json:
<select id='jsonFile' name='jsonFile'>
	<option value=''>Select a File</option>
	<?php foreach ($jsonFiles as $fileName) : ?>
		<option value='<?php echo 'simpledata/' . $fileName;?>'><?php echo $fileName;?></option>
	<?php endforeach ?>
</select>
&nbsp;&nbsp; User Uploads:<span id='useruploads'>
<select id='userFile' name='userFile'>
	<option value=''>Select a File</option>
	<?php foreach ($userFiles as $fileName) : ?>
		<option value='<?php echo $userPath . '/' . $fileName;?>'><?php echo $fileName;?></option>
	<?php endforeach ?>
</select>
	</span>


	<input type="file" id="myFile" name="filename">
	<input name='uploadfile' type="submit">
	<input type='button' value='reload' id='reload'>
	Iteratie marge
	<select name='lowercheckmargin' id='lowercheckmargin'>
		<option value='0'>0</option>
		<option value='1'>-1</option>
		<option value='2'>-2</option>
		<option value='10'>-10</option>
		<option value='100'>-100</option>
	</select>
	dagen

</form>
<div id='result'></div>
<p>&nbsp;</p>
<p>Let op:<br/>
Om de status te bepalen staat in de documentatie dat er op afspraakdatum gesorteerd moet worden om zo snel mogelijk te stoppen met de verwerking.<br/>
Dat gaat verkeerd wanneer een MA wordt tussengevoegd. Daarom sorteer ik op <strong>startdatum</strong>! Dat geeft het goede resultaat!
	</p>
<p>25-02-2024: MA's en MGB worden verwerkt. De andere bouwstenen nog niet.</p>
<p>Code staat op github <a href="https://github.com/bartjens/consolidatie">https://github.com/bartjens/consolidatie</a></p>
</html>
</body>
