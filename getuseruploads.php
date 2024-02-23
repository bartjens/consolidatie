<?php
$userID = $_GET['userID'];
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
<select id='userFile' name='userFile'>
	<option value=''>Select a File</option>
	<?php foreach ($userFiles as $fileName) : ?>
		<option value='<?php echo $userPath . '/' . $fileName;?>'><?php echo $fileName;?></option>
	<?php endforeach ?>
</select>