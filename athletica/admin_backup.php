<?php

/********************
 *
 *	admin_backup.php
 *	----------------
 *	
 *******************/

require('./lib/cl_gui_page.lib.php');

require('./lib/common.lib.php');

if(AA_connectToDB() == FALSE)	{		// invalid DB connection
	return;
}

/*
	Before restoring, the backup file will be verified according to the
	following attributes:
	1.) the following ID-String must be identical to guarantee the same
		DB version.
	2.) the number of TRUNCATE statements don't have to be equal because
		of the empty tables delivered by the backup
*/

set_time_limit(3600); // the script will break if this is not set

$idstring = "# $cfgApplicationName $cfgApplicationVersion\n";

$compatible = array("SLV_1.4", "SLV_1.5", "SLV_1.6", "SLV_1.7", "SLV_1.7.1", "SLV_1.7.2"
		, "SLV_1.8", "SLV_1.8.1", "SLV_1.8.2", "SLV_1.9", "3.0", "3.0.1", "3.1", "3.1.1", "3.1.2", "3.2", "3.2.1", "3.2.2", "3.2.3");

if($_GET['arg'] == 'backup')
{
   $result = mysql_list_tables($cfgDBname);
	
	if(mysql_errno() > 0)
	{
		AA_printErrorMsg(mysql_errno() . ": " . mysql_error());
	}
	else
	{
		if(mysql_num_rows($result) > 0)	// any table
		{
			// print http header
			header("Content-type: application/octetstream");
			header("Content-Disposition: inline; filename=athletica.sql");
		  header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Pragma: public');

			echo "$idstring";
			echo "# Database Dump:\n";
			echo "# Date/time: " . date("d.M.Y, H:i:s") . "\n";
			echo "# ----------------------------------------------------------\n";
		}
	while ($row = mysql_fetch_row($result))
		{
			$res = mysql_query("SELECT * FROM $row[0]");
			
			// truncate in each case!
			echo "\n#\n";
			echo "# Table '$row[0]'\n";
			echo "#\n\n";
			echo "TRUNCATE TABLE $row[0];\n";
			
			$fieldArray = array();
			if(mysql_num_rows($res) > 0)	// any content
			{
				echo "INSERT INTO $row[0] \n";
				
				$fields = mysql_query("SHOW COLUMNS FROM $row[0]");
				$tmpf = "(";
				while($f = mysql_fetch_assoc($fields)){
					$tmpf .= "`".$f['Field']."`, ";
					$fieldArray[] = $f;
				}
				echo substr($tmpf,0,-2).") VALUES\n";
				
			}

			unset($values);
			while($tabrow = mysql_fetch_assoc($res))
			{
				if(!empty($values)) {	// print previous row
					echo "$values),\n";
				}

				$values = "(";
				$cma = "";
				/*$fields = mysql_list_fields($cfgDBname, $row[0]);
				$columns = mysql_num_fields($fields);
				for ($i = 0; $i < $columns; $i++) {
					if(mysql_field_type($res, $i) == 'int') {	
						$values = $values . $cma . $tabrow[$i];
					}
					else {
						$values = $values . $cma . "'" . addslashes($tabrow[$i]) . "'";
					}
					$cma = ", ";
				}*/
				foreach($fieldArray as $f){
					if(substr($f['Type'],0,3) == 'int') {	
						$values = $values . $cma . $tabrow[$f['Field']];
					}
					else {
						$values = $values . $cma . "'" . addslashes($tabrow[$f['Field']]) . "'";
					}
					$cma = ", ";
				}
			}		// End while every table row

			if(mysql_num_rows($res) > 0)	// any content
			{
				echo "$values);#*\n";		// print last row
								// the '#*' is needed for finding the end of the insert statement
								// (if there are semicolons in a field value)
			}
			
			mysql_free_result($res);

			echo "\n# ----------------------------------------------------------\n";
		}		// End while every table

		if(mysql_num_rows($result) > 0) {	// any table
			echo "\n#*ENDLINE"; // termination for validating
						// has to be on the last 9 characters
			flush();
		}

		mysql_free_result($result);
	}
}
else if ($_POST['arg'] == 'restore')
{
	$page = new GUI_Page('admin_backup');
	$page->startPage();
	$page->printPageTitle($strRestore);
	
	?>
<table class="dialog">
	<?php
	
	// get uploaded SQL file and read its content
	$fd = fopen($_FILES['bkupfile']['tmp_name'], 'rb');
	$content = fread($fd, filesize($_FILES['bkupfile']['tmp_name']));
	//fclose($fd);
	
	// since version 1.4 the include statements contain the table fields,
	// so they can by restored in later versions
	$validBackup = false;
	$backupVersion = "";
	foreach($compatible as $v){
		$idstring = "# $cfgApplicationName $v\n";
		$idstring2 = "# $cfgApplicationName $v\r";
		if((strncmp($content, $idstring, strlen($idstring)) == 0) || (strncmp($content, $idstring2, strlen($idstring2)) == 0)){
			$validBackup = true;
			$backupVersion = $v;
			break;
		}
	}
	
	// cut SLV_ from version
	$shortVersion = ""; // version without SLV_
	if(substr($backupVersion,0,4) == "SLV_"){
		$shortVersion = substr($backupVersion, 4, 3);
	}else{
		$shortVersion = substr($backupVersion, 0, 3);
	}
	
	// since version 1.9 the backup contains a termination line
	if($shortVersion >= 1.9){
		$term = substr($content, -9);
		if($term != "#*ENDLINE"){
			$validBackup = false;
		}else{
			echo "<tr><th class='secure'>-- $strBackupStatus2 --</th></tr>";
		}
		
	}else{
		
		echo "<tr><th class='insecure'>-- $strBackupStatus1 --</th></tr>";
		
	}
	
	if(!$validBackup)	// invalid backup ID
	{
		AA_printErrorMsg($strErrInvalidBackupFile);
	}
	else
	{
		$error = false;			// backup error
		$sqlTruncate = array();		// array to hold TRUNCATE statements;	
		$sqlInsert = array();		// array to hold INSERT statements;	
		
		// as of 1.8 the table omega_konfiguration is named zeitmessung
		$content = str_replace("omega_konfiguration", "zeitmessung", $content);
		
		while(strlen($content) > 0)
		{
			$content = strstr($content, "TRUNCATE");
			if($content == false) {
				break;
			}
			$length = strpos($content, ";");
			if($length == false) {
				break;
			}
			$sqlTruncate[]	= substr($content, 0, $length+1);
			$content = substr($content, $length+1);
		}
		
		rewind($fd);
		$content = fread($fd, filesize($_FILES['bkupfile']['tmp_name']));
		
		if($shortVersion < 1.9){ // replace certain things in older backups
			// as of 1.7 the field xMehrkampfcode is named as Mehrkampfcode
			$content = str_replace("xMehrkampfcode", "Mehrkampfcode", $content);
			// as of 1.7.1 the field RegionSpezial is named as xRegion
			$content = str_replace("RegionSpezial", "xRegion", $content);
			// as of 1.8 the table omega_konfiguration is named zeitmessung
			$content = str_replace("omega_konfiguration", "zeitmessung", $content);
			// --> the fields are the same but xOMEGA_Konfiguration
			$content = str_replace("xOMEGA_Konfiguration", "xZeitmessung", $content);
		}
		
		while(strlen($content) > 0)
		{
			$content = strstr($content, "INSERT");
			if($content == false) {
				break;
			}
			$length = strpos($content, ";#*");
			//$length = strpos($content, ";");
			if($length == false) {
				break;
			}
			$sqlInsert[]	= substr($content, 0, $length+1);
			$content = substr($content, $length+1);
		}
		
		// output information about number of truncate and insert statements
		echo "<tr><td class='dialog'>";
		echo "Truncating ".count($sqlTruncate)." tables<br>";
		echo "Inserting values of ".count($sqlInsert)." tables";
		echo "</td></tr>";
		
		// to less tables to truncate -> not a valid backup
		// this isn't relevant for version 1.9 and above ( because of the termination line)
		if($shortVersion < 1.9 && count($sqlTruncate) < 30){
			AA_printErrorMsg($strBackupDamaged);
			$error = true;
		}else{
			
			// set max_allowed_packet for inserting very big queries
			mysql_pconnect( $GLOBALS['cfgDBhost'].':'.$GLOBALS['cfgDBport'], "root", "");
			mysql_select_db($GLOBALS['cfgDBname']);
			mysql_query("SET @@global.max_allowed_packet=10000000");
			if(mysql_errno() > 0){
				$error = true;
				AA_printErrorMsg(mysql_errno().": ".mysql_error());
			}
			
			// check if equal amount of truncate and insert statements
			/*if(count($sqlTruncate) != count($sqlInsert))
			{
				AA_printErrorMsg($strErrInvalidBackupFile);
			}
			else
			{*/
				// process every SQL statement
				for($i=0; $i < count($sqlTruncate); $i++)
				{
					//echo "$sqlTruncate[$i] ";
					mysql_query($sqlTruncate[$i]);
					if(mysql_errno() > 0)
					{
						$error = true;
						echo mysql_errno() . ": " . mysql_error() . "<br>";
						AA_printErrorMsg(mysql_errno() . ": " . mysql_error());
						break;
					}
					//echo "OK<br>";
				}
				
				for($i=0; $i < count($sqlInsert); $i++)
				{
					//echo substr($sqlInsert[$i], 0, strpos($sqlInsert[$i], " VALUES")) . " ... ";
					mysql_query($sqlInsert[$i]);
					if(mysql_errno() > 0)
					{
						$error = true;
						echo mysql_errno() . ": " . mysql_error() . "<br>";
						echo $sqlInsert[$i];
						AA_printErrorMsg(mysql_errno() . ": " . mysql_error());
						break;
					}
					//echo "OK<br><br>";
				}
			//}	// ET invalid content
			
			// since 1.8 the roundtypes have a code field, update if backup is older
			if($shortVersion < 1.8){
				mysql_query("UPDATE `rundentyp` SET `Code` = 'V' WHERE `xRundentyp` =1 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'F' WHERE `xRundentyp` =2 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'Z' WHERE `xRundentyp` =3 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'Q' WHERE `xRundentyp` =5 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'S' WHERE `xRundentyp` =6 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'X' WHERE `xRundentyp` =7 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = 'D' WHERE `xRundentyp` =8 LIMIT 1 ");
				mysql_query("UPDATE `rundentyp` SET `Code` = '0' WHERE `xRundentyp` =9 LIMIT 1 ");
			}
			
			// since 1.9 the categories hava a gender
			if($shortVersion < 1.9){
				mysql_query("UPDATE kategorie SET
						Geschlecht = 'w' 
					WHERE Code = 'WOM_' 
						OR Code = 'U23W' 
						OR Code = 'U20W' 
						OR Code = 'U18W' 
						OR Code = 'U16W' 
						OR Code = 'U14W' 
						OR Code = 'U12W'");
			}
			
			// new categories U10M and U10W and disciplines BALL80 and 300H91.4 since 3.0
			if($shortVersion < 3.1){
				// categories
				mysql_query("INSERT IGNORE INTO kategorie 
											   (xKategorie
												, Kurzname
												, Name
												, Anzeige
												, Alterslimite
												, Code
												, Geschlecht)
										VALUES (''
												, 'U10M'
												, 'U10 M'
												, '7'
												, '9'
												, 'U10M'
												, 'm');");
				mysql_query("INSERT IGNORE INTO kategorie 
											   (xKategorie
												, Kurzname
												, Name
												, Anzeige
												, Alterslimite
												, Code
												, Geschlecht)
										VALUES (''
												, 'U10W'
												, 'U10 W'
												, '15'
												, '18'
												, 'U10W'
												, 'w');");
												
				// disciplines
				mysql_query("INSERT IGNORE INTO disziplin 
											   (xDisziplin
												, Kurzname
												, Name
												, Anzeige
												, Seriegroesse
												, Staffellaeufer
												, Typ
												, Appellzeit
												, Stellzeit
												, Strecke
												, Code
												, xOMEGA_Typ)
										VALUES ('', 
												'BALL80'
												, 'Ball 80 g'
												, '385'
												, '6'
												, '0' 
												, '8'
												, '01:00:00'
												, '00:20:00'
												, '0'
												, '385'
												, '1');");
				mysql_query("UPDATE disziplin 
								SET Code = 385 
							  WHERE Anzeige = 385 
								AND Kurzname = 'BALL80';");
								
				mysql_query("INSERT IGNORE INTO disziplin 
											   (xDisziplin
												, Kurzname
												, Name
												, Anzeige
												, Seriegroesse
												, Staffellaeufer
												, Typ
												, Appellzeit
												, Stellzeit
												, Strecke
												, Code
												, xOMEGA_Typ)
										VALUES ('', 
												'300H91.4'
												, '300 m H�rden 91.4'
												, '289'
												, '6'
												, '0' 
												, '2'
												, '01:00:00'
												, '00:15:00'
												, '300'
												, '289'
												, '4');");
			}
			
			// new categories SENM and SENW
			if($shortVersion < 3.2){
				// categories
				mysql_query("INSERT IGNORE INTO kategorie 
											   (xKategorie
												, Kurzname
												, Name
												, Anzeige
												, Alterslimite
												, Code
												, Geschlecht)
										VALUES (''
												, 'MASM'
												, 'MASTERS M'
												, '2'
												, '99'
												, 'MASM'
												, 'm');");
				mysql_query("INSERT IGNORE INTO kategorie 
											   (xKategorie
												, Kurzname
												, Name
												, Anzeige
												, Alterslimite
												, Code
												, Geschlecht)
										VALUES (''
												, 'MASW'
												, 'MASTERS W'
												, '11'
												, '99'
												, 'MASW'
												, 'w');");
			}
			
			// security updates
			mysql_query("UPDATE kategorie 
							SET Code = 'U10M' 
						  WHERE Kurzname = 'U10M';");
			mysql_query("UPDATE kategorie 
							SET Code = 'U10W' 
						  WHERE Kurzname = 'U10W';");
						  
			mysql_query("UPDATE kategorie 
							SET Kurzname = 'MASM', 
								Name = 'MASTERS M', 
								Code = 'MASM' 
						  WHERE Kurzname = 'SENM';");
			mysql_query("UPDATE kategorie 
							SET Kurzname = 'MASW', 
								Name = 'MASTERS W', 
								Code = 'MASW' 
						  WHERE Kurzname = 'SENW';");
			
			// update nations for all backups
			mysql_query("TRUNCATE TABLE land;");
			mysql_query("INSERT INTO land(xCode, Name, Sortierwert) VALUES 
									 ('SUI', 'Switzerland', 1),
									 ('AFG', 'Afghanistan', 2),
									 ('ALB', 'Albania', 3),
									 ('ALG', 'Algeria', 4),
									 ('ASA', 'American Samoa', 5),
									 ('AND', 'Andorra', 6),
									 ('ANG', 'Angola', 7),
									 ('AIA', 'Anguilla', 8),
									 ('ANT', 'Antigua & Barbuda', 9),
									 ('ARG', 'Argentina', 10),
									 ('ARM', 'Armenia', 11),
									 ('ARU', 'Aruba', 12),
									 ('AUS', 'Australia', 13),
									 ('AUT', 'Austria', 14),
									 ('AZE', 'Azerbaijan', 15),
									 ('BAH', 'Bahamas', 16),
									 ('BRN', 'Bahrain', 17),
									 ('BAN', 'Bangladesh', 18),
									 ('BAR', 'Barbados', 19),
									 ('BLR', 'Belarus', 20),
									 ('BEL', 'Belgium', 21),
									 ('BIZ', 'Belize', 22),
									 ('BEN', 'Benin', 23),
									 ('BER', 'Bermuda', 24),
									 ('BHU', 'Bhutan', 25),
									 ('BOL', 'Bolivia', 26),
									 ('BIH', 'Bosnia Herzegovina', 27),
									 ('BOT', 'Botswana', 28),
									 ('BRA', 'Brazil', 29),
									 ('BRU', 'Brunei', 30),
									 ('BUL', 'Bulgaria', 31),
									 ('BRK', 'Burkina Faso', 32),
									 ('BDI', 'Burundi', 33),
									 ('CAM', 'Cambodia', 34),
									 ('CMR', 'Cameroon', 35),
									 ('CAN', 'Canada', 36),
									 ('CPV', 'Cape Verde Islands', 37),
									 ('CAY', 'Cayman Islands', 38),
									 ('CAF', 'Central African Republic', 39),
									 ('CHA', 'Chad', 40),
									 ('CHI', 'Chile', 41),
									 ('CHN', 'China', 42),
									 ('COL', 'Colombia', 43),
									 ('COM', 'Comoros', 44),
									 ('CGO', 'Congo', 45),
									 ('COD', 'Congo [Zaire]', 46),
									 ('COK', 'Cook Islands', 47),
									 ('CRC', 'Costa Rica', 48),
									 ('CIV', 'Ivory Coast', 49),
									 ('CRO', 'Croatia', 50),
									 ('CUB', 'Cuba', 51),
									 ('CYP', 'Cyprus', 52),
									 ('CZE', 'Czech Republic', 53),
									 ('DEN', 'Denmark', 54),
									 ('DJI', 'Djibouti', 55),
									 ('DMA', 'Dominica', 56),
									 ('DOM', 'Dominican Republic', 57),
									 ('TLS', 'East Timor', 58),
									 ('ECU', 'Ecuador', 59),
									 ('EGY', 'Egypt', 60),
									 ('ESA', 'El Salvador', 61),
									 ('GEQ', 'Equatorial Guinea', 62),
									 ('ERI', 'Eritrea', 63),
									 ('EST', 'Estonia', 64),
									 ('ETH', 'Ethiopia', 65),
									 ('FIJ', 'Fiji', 66),
									 ('FIN', 'Finland', 67),
									 ('FRA', 'France', 68),
									 ('GAB', 'Gabon', 69),
									 ('GAM', 'Gambia', 70),
									 ('GEO', 'Georgia', 71),
									 ('GER', 'Germany', 72),
									 ('GHA', 'Ghana', 73),
									 ('GIB', 'Gibraltar', 74),
									 ('GBR', 'Great Britain & NI', 75),
									 ('GRE', 'Greece', 76),
									 ('GRN', 'Grenada', 77),
									 ('GUM', 'Guam', 78),
									 ('GUA', 'Guatemala', 79),
									 ('GUI', 'Guinea', 80),
									 ('GBS', 'Guinea-Bissau', 81),
									 ('GUY', 'Guyana', 82),
									 ('HAI', 'Haiti', 83),
									 ('HON', 'Honduras', 84),
									 ('HKG', 'Hong Kong', 85),
									 ('HUN', 'Hungary', 86),
									 ('ISL', 'Iceland', 87),
									 ('IND', 'India', 88),
									 ('INA', 'Indonesia', 89),
									 ('IRI', 'Iran', 90),
									 ('IRQ', 'Iraq', 91),
									 ('IRL', 'Ireland', 92),
									 ('ISR', 'Israel', 93),
									 ('ITA', 'Italy', 94),
									 ('JAM', 'Jamaica', 95),
									 ('JPN', 'Japan', 96),
									 ('JOR', 'Jordan', 97),
									 ('KAZ', 'Kazakhstan', 98),
									 ('KEN', 'Kenya', 99),
									 ('KIR', 'Kiribati', 100),
									 ('KOR', 'Korea', 101),
									 ('KUW', 'Kuwait', 102),
									 ('KGZ', 'Kirgizstan', 103),
									 ('LAO', 'Laos', 104),
									 ('LAT', 'Latvia', 105),
									 ('LIB', 'Lebanon', 106),
									 ('LES', 'Lesotho', 107),
									 ('LBR', 'Liberia', 108),
									 ('LIE', 'Liechtenstein', 109),
									 ('LTU', 'Lithuania', 110),
									 ('LUX', 'Luxembourg', 111),
									 ('LBA', 'Libya', 112),
									 ('MAC', 'Macao', 113),
									 ('MKD', 'Macedonia', 114),
									 ('MAD', 'Madagascar', 115),
									 ('MAW', 'Malawi', 116),
									 ('MAS', 'Malaysia', 117),
									 ('MDV', 'Maldives', 118),
									 ('MLI', 'Mali', 119),
									 ('MLT', 'Malta', 120),
									 ('MSH', 'Marshall Islands', 121),
									 ('MTN', 'Mauritania', 122),
									 ('MRI', 'Mauritius', 123),
									 ('MEX', 'Mexico', 124),
									 ('FSM', 'Micronesia', 125),
									 ('MDA', 'Moldova', 126),
									 ('MON', 'Monaco', 127),
									 ('MGL', 'Mongolia', 128),
									 ('MNE', 'Montenegro', 129),
									 ('MNT', 'Montserrat', 130),
									 ('MAR', 'Morocco', 131),
									 ('MOZ', 'Mozambique', 132),
									 ('MYA', 'Myanmar [Burma]', 133),
									 ('NAM', 'Namibia', 134),
									 ('NRU', 'Nauru', 135),
									 ('NEP', 'Nepal', 136),
									 ('NED', 'Netherlands', 137),
									 ('AHO', 'Netherlands Antilles', 138),
									 ('NZL', 'New Zealand', 139),
									 ('NCA', 'Nicaragua', 140),
									 ('NIG', 'Niger', 141),
									 ('NGR', 'Nigeria', 142),
									 ('NFI', 'Norfolk Islands', 143),
									 ('PRK', 'North Korea', 144),
									 ('NOR', 'Norway', 145),
									 ('OMN', 'Oman', 146),
									 ('PAK', 'Pakistan', 147),
									 ('PLW', 'Palau', 148),
									 ('PLE', 'Palestine', 149),
									 ('PAN', 'Panama', 150),
									 ('NGU', 'Papua New Guinea', 151),
									 ('PAR', 'Paraguay', 152),
									 ('PER', 'Peru', 153),
									 ('PHI', 'Philippines', 154),
									 ('POL', 'Poland', 155),
									 ('POR', 'Portugal', 156),
									 ('PUR', 'Puerto Rico', 157),
									 ('QAT', 'Qatar', 158),
									 ('ROM', 'Romania', 159),
									 ('RUS', 'Russia', 160),
									 ('RWA', 'Rwanda', 161),
									 ('SMR', 'San Marino', 162),
									 ('STP', 'S�o Tome & Princip�', 163),
									 ('KSA', 'Saudi Arabia', 164),
									 ('SEN', 'Senegal', 165),
									 ('SRB', 'Serbia', 166),
									 ('SEY', 'Seychelles', 167),
									 ('SLE', 'Sierra Leone', 168),
									 ('SIN', 'Singapore', 169),
									 ('SVK', 'Slovakia', 170),
									 ('SLO', 'Slovenia', 171),
									 ('SOL', 'Solomon Islands', 172),
									 ('SOM', 'Somalia', 173),
									 ('RSA', 'South Africa', 174),
									 ('ESP', 'Spain', 175),
									 ('SKN', 'St. Kitts & Nevis', 176),
									 ('SRI', 'Sri Lanka', 177),
									 ('LCA', 'St. Lucia', 178),
									 ('VIN', 'St. Vincent & the Grenadines', 179),
									 ('SUD', 'Sudan', 180),
									 ('SUR', 'Surinam', 181),
									 ('SWZ', 'Swaziland', 182),
									 ('SWE', 'Sweden', 183),
									 ('SYR', 'Syria', 185),
									 ('TAH', 'Tahiti', 186),
									 ('TPE', 'Taiwan', 187),
									 ('TAD', 'Tadjikistan', 188),
									 ('TAN', 'Tanzania', 189),
									 ('THA', 'Thailand', 190),
									 ('TOG', 'Togo', 191),
									 ('TGA', 'Tonga', 192),
									 ('TRI', 'Trinidad & Tobago', 193),
									 ('TUN', 'Tunisia', 194),
									 ('TUR', 'Turkey', 195),
									 ('TKM', 'Turkmenistan', 196),
									 ('TKS', 'Turks & Caicos Islands', 197),
									 ('UGA', 'Uganda', 198),
									 ('UKR', 'Ukraine', 199),
									 ('UAE', 'United Arab Emirates', 200),
									 ('USA', 'United States', 201),
									 ('URU', 'Uruguay', 202),
									 ('UZB', 'Uzbekistan', 203),
									 ('VAN', 'Vanuatu', 204),
									 ('VEN', 'Venezuela', 205),
									 ('VIE', 'Vietnam', 206),
									 ('ISV', 'Virgin Islands', 207),
									 ('SAM', 'Western Samoa', 208),
									 ('YEM', 'Yemen', 209),
									 ('ZAM', 'Zambia', 210),
									 ('ZIM', 'Zimbabwe', 211);");
			
			// check AUTO_INCREMENT (min. 100) of Wertungstabelle
			$sql_wt = "SELECT xWertungstabelle 
						 FROM wertungstabelle 
						WHERE xWertungstabelle < 100;";
			$query_wt = mysql_query($sql_wt);
			
			while($row_wt = mysql_fetch_assoc($query_wt)){
				$sql_max = "SELECT MAX(xWertungstabelle) AS max_id 
							  FROM wertungstabelle;";
				$query_max = mysql_query($sql_max);
				$max_id = (mysql_result($query_max, 0, 'max_id')>=100) ? mysql_result($query_max, 0, 'max_id') : 99;
				$new_id = ($max_id + 1);
				
				$sql_up = "UPDATE wertungstabelle 
							  SET xWertungstabelle = ".$new_id." 
							WHERE xWertungstabelle = ".$row_wt['xWertungstabelle'].";";
				$query_up = mysql_query($sql_up);
				
				if($query_up){
					$sql_up2 = "UPDATE wertungstabelle_punkte 
								   SET xWertungstabelle = ".$new_id." 
								 WHERE xWertungstabelle = ".$row_wt['xWertungstabelle'].";";
					$query_up2 = mysql_query($sql_up2);
				}
			}
			
			$sql_max = "SELECT MAX(xWertungstabelle) AS max_id 
						  FROM wertungstabelle;";
			$query_max = mysql_query($sql_max);
			$max_id = (mysql_num_rows($query_max)==1 && mysql_result($query_max, 0, 'max_id')>0) ? mysql_result($query_max, 0, 'max_id') : 99;
			$new_id = ($max_id + 1);
			
			$sql_ai = "ALTER TABLE wertungstabelle 
								   AUTO_INCREMENT = ".$new_id.";";
			$query_ai = mysql_query($sql_ai);
			
		}
		
		if(!$error){
			echo "<tr><th class='dialog'>$strBackupSucceeded</th></tr>";
			
			setcookie("meeting_id", "", time()-3600);
			setcookie("meeting", "", time()-3600);
			if(isset($_SESSION['meeting_infos'])){
				unset($_SESSION['meeting_infos']);
			}
			
			$sql = "SELECT * 
					  FROM meeting;";
			$query = mysql_query($sql);
			
			if($query && mysql_num_rows($query)==1){
				$row = mysql_fetch_assoc($query);
				
				// store cookies on browser
				setcookie("meeting_id", $row['xMeeting'], time()+$cfgCookieExpires);
				setcookie("meeting", stripslashes($row['Name']), time()+$cfgCookieExpires);
				// update current cookies
				$_COOKIE['meeting_id'] = $row['xMeeting'];
				$_COOKIE['meeting'] = stripslashes($row['Name']);
				
				$_SESSION['meeting_infos'] = $row;
			} else {
				?>
				</table>
				<table>
					<tr>
						<td>
							<br/>
							<input type="button" value="<?=$strSelectMeeting?> &raquo;" onclick="location.href='meeting.php'">
						</td>
					</tr>
				<?php
			}
		}
		
	}	// ET invalid backup ID
	
	fclose($fd);
	
	?>
</table>
	<?php
	
	$page->endPage();

}
?>