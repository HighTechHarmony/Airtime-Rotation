<?php


echo date("D M d, Y G:i a\n");

//echo phpversion()."\n";
//exit(1);

// Test our database connection
$conn = pg_connect("host=localhost port=5432 dbname=airtime user=airtime password=airtime");
if (!$conn) {
    echo "Couldn't connect to Airtime DB.\n";
    exit(1);
}

if (count($argv) > 1){
    if ($argv[1] == "--clean"){    
        $tables = array("cc_schedule", "cc_show_instances", "cc_show");
        
        foreach($tables as $table){
            $query = "DELETE FROM $table";
            echo $query.PHP_EOL;
            query($conn, $query);
        }
        rabbitMqNotify();
        exit(0);
    } else { 
        $str = <<<EOD
This script schedules is designed to be run once every minute. If there is an upcoming scheduling gap,
it will scheduled a rotation show to fill in until the next show.
It modifies the database tables cc_schedule, cc_show_instances and cc_show.
You can clean up (AKA compeletely destroy) the airtime schedule using the --clean option.
EOD;
        echo $str.PHP_EOL;
        exit(0);
    }
}





// See if there is a gap coming, and deal with it
$gapStatus=(gapComing($conn));

//$gapStatus =1; // Uncomment for testing
if ($gapStatus)  //Anything non-zero means we need to take action on an upcoming gap
    {
        echo "It looks like we have a scheduling gap of type $gapStatus.\n";

        //The start time of the new show will be now
        $nowDateTime = new DateTime("now", new DateTimeZone("UTC"));
        $startsDateTime = clone $nowDateTime;
        // The below line may be needed if new rotation shows are started too early and overlap the end of the current show
        //if ($gapStatus==1) {
        $startsDateTime->add(new DateInterval('PT1M'));  // Add 1 minute if a current show was detected by gapComing(), otherwise (i.e. 2 or higher) we will expedite
        //}
        $startsString = $startsDateTime->format("Y-m-d H:i:s");


        // Determine the end time of the show
        $endsDateTime = getEndTime_1hr($conn);
        $endsString = $endsDateTime->format("Y-m-d H:i:s");        

        echo "Scheduling rotation until ".$endsString." UTC\n";





        // /*  Below will be replaced by the smart, makePlaylist() function */
        // // Generate a list of content based on songs
        // //$files = getFileFromCcFiles($conn);
        // $songs = getSongsFromCcFiles($conn);

        // //print "Songs:\n";
        // //print_r ($songs);

        // $ids = getIDsFromCcFiles($conn);
        // // print "IDs:\n";
        // // print_r ($ids);

        // // Intersperse station IDs 
        // $files = intersperse ($songs,$ids);

        //echo "startsDateTime ".$startsDateTime->format("Y-m-d H:i:s");

        // Get the Show duration as a DateTime object
        $show_durationDateTime=new DateTime('00:00');
        $show_durationDateTime->add(date_diff($startsDateTime,$endsDateTime));

        // Pass it to makePlaylist and get the show playlist
        $files=makePlaylist($conn, $show_durationDateTime);
        //print_r($files);

	// Uncomment below to preview the resulting playlist on the console
	//foreach ($files as $item)
	//{
	//	print $item[5]."\n";
	//}

        echo "Show playlist has ".count($files)." elements\n";
        

        // Uncomment for test/dry runs
        //exit(1);




        // Create a new show and get the show ID
        $show_id = insertIntoCcShow($conn);
        
        // Create an instance for the new show and get an id
        $show_instance_id = insertIntoCcShowInstances($conn, $show_id, $startsDateTime, $endsDateTime, $files);

        // Schedule all the files as show content
        insertIntoCcSchedule($conn, $files, $show_instance_id, $startsDateTime, $endsDateTime);

        rabbitMqNotify();

        echo PHP_EOL."Show scheduled for $startsString (UTC)".PHP_EOL;
    }

else
    {
        echo "Not doing anything, as there doesn't appear to be scheduling gap 1 minute from now.\n";
    }














/************************************************************************************************************************
/* Begin function definitions
/**************************************************************************/






// Horrible legacy function that retrieves 1000 files from the library at random.
// Superseded by get SongsFromCcFiles()
// function getFileFromCcFiles($conn){
//     $query = "SELECT * from cc_files WHERE cc_files.file_exists=true  ORDER BY random()  LIMIT 100";
//     $result = pg_query($conn, $query);
//     $files = array();
//     while ($row = pg_fetch_array($result)) {
//         $files[] = $row;
        
//     }
        
//     if (count($files) == 0){
//         echo "Library is empty. Could not choose random file.";
//         exit(1);
//     }
    
//     return $files;
    
// }



/******************************************/
// FUNCTION: getSongsFromCcFiles ()
// DESCRIPTION: 
// Mindlessly retrieves 100 songs that are not IDs/podcasts/shows from the library, at random.
//
// TAKES:
// Takes: DB connection object
// 
// RETURNS:
// Returns: array of files infos
/******************************************/


function getSongsFromCcFiles($conn){    
    $query = "SELECT * FROM cc_files WHERE genre<>'ID' AND genre<>'Podcast' AND genre<>'Show' AND cc_files.file_exists=true  ORDER BY random()  LIMIT 100";    
    $result = pg_query($conn, $query);
    $files = array();
    while ($row = pg_fetch_array($result)) {
        $files[] = $row;
        
    }
        
    if (count($files) == 0){
        echo "There are no songs in the library. Could not choose random song.";
        //exit(1);
    }
    
    return $files;
    
}




/******************************************/
// FUNCTION: makePlaylist ()
// DESCRIPTION: 
// Attempts to intelligently assemble a show playlist, including IDs, and have it actually 
// come out close to the desired duration
//
// TAKES:
// Takes: DB connection object, $target_duration as a DateTime object, and a ratio of ids to songs (duration)
// Example: .016 = 1 minute of IDs for 60 minutes of songs
// 
// RETURNS:
// Returns: array of files infos
/******************************************/

function makePlaylist($conn, $target_duration, $id_ratio=.016) {

    // $iterations
    // Number of attempts to make, increase for better quality but lose randomness
    // Good results yielded with 50
    $iterations=50;  

    $files = array();
    $test_playlist = array();
    $best_candidate_playlist = array();
    $best_candidate_duration = new DateTime('00:00');
    $loop_count=0;
    

    $target_durationString =  $target_duration->format("H:i:s");
    echo "makePlaylist() Initial target duration is ". $target_durationString ."\n";

    
    // Calculates the desired ID content duration from the given ratio $id_ratio
    $desired_id_duration_from_ratio=multiplyDateTimeBy($target_duration,$id_ratio);
    echo "makePlaylist() ID duration from ratio is ".$desired_id_duration_from_ratio->format("H:i:s")."\n";

    // Here we need to substract an ideal margin to make room for IDs
    // Temporarily hardcoded to 1 minute, but will eventually be calculated by a ratio
    //$target_duration_without_ids=clone($target_duration);
    $target_duration_without_ids=new DateTime("00:00");
    //$target_duration_without_ids->sub(new DateInterval('PT1M')); 
    $target_duration_without_ids->add(date_diff($desired_id_duration_from_ratio,$target_duration));
    
        // $id_duration=getTimeRatio($duration, $id_ratio);    

    $target_durationString =  $target_duration_without_ids->format("Y-m-d H:i:s");
    echo "makePlaylist() Adjusted for IDs target duration is ". $target_durationString ."\n";

    while (++$loop_count<$iterations) 
    {
        $duration = new DateTime('00:00'); // Interval of zero
        $testDuration =  new DateTime('00:00');  // Interval of zero        
        $files = array();
        $playlist = array();


        // Grab a list of 100 songs from the database
        $query = "SELECT * FROM cc_files WHERE 
        ((genre<>'ID' 
        AND genre<>'Podcast' 
        AND genre<>'Show'
        AND genre<>'RotEx' 
        AND genre<>'Rotex')
        OR genre is null)
        AND cc_files.file_exists=true
        AND (lptime < NOW() at time zone 'utc' - INTERVAL '40 hours' OR lptime is null)
        ORDER BY random()  LIMIT 50";   

        $result = pg_query($conn, $query);
        
        while ($row = pg_fetch_array($result)) {
            $files[] = $row;

	    // Uncomment to see the initial pool of files on the console
            //print $row[5]."\n";
        }
            
        if (count($files) == 0){
            echo "There are no songs in the library. Could not choose random song.";
            //exit(1);
        }


        // Truncate the list to one song before the target duration
        foreach($files as $file){            
            $songlengthDI = getDateInterval($file["length"]);

            // Test to see if this will put us over\
            $testDuration = clone ($duration);

            $testDuration->add($songlengthDI);
            if ($testDuration > $target_duration_without_ids) 
            {
                break;
            }

            else 
                {
                    $duration = clone($testDuration);
                    $playlist[] = $file;  // Add this file to the playlist
                }
        }   // End of foreach file



        // See how close we got
        // Compare to the best candidate
        if ($duration >= $best_candidate_duration)
        {

            // $best_candidate_durationDT=new DateTime("00:00");
            // $differenceDI=$date_diff($best_candidate_duration,$duration)
            // $differenceDT=new DateTime("00:00");
            // $differenceDT->add($differenceDI);
            // if ($difference)

            // If this was better, store this playlist and duration as best_candidate
            $best_candidate_playlist=$playlist;
            $best_candidate_duration=clone($duration);

            // If we nailed it, there is no point in continuing.
            if ($duration == $target_duration_without_ids) {echo "makePlaylist() nailed the song playlist in ".$loop_count." tries!\n"; break;}
        }
        
        //echo "Trial ".count($playlist)." songs, duration ".$duration->format("H:i:s")."\n";

        
    }

    echo "makePlaylist() Best: ".count($best_candidate_playlist)." songs, duration ".$best_candidate_duration->format("H:i:s")."\n";

    

    // Next we will insert IDs as needed to round out the duration


    // Subtract the song playlist duration from the target duration
    $target_id_duration=new DateTime('00:00');

    //echo "makePlaylist() Subtracting ".$best_candidate_duration->format("H:i:s")." from ".$target_duration->format("H:i:s")."\n";
    $target_id_duration->add(date_diff($best_candidate_duration,$target_duration));
    echo "makePlaylist() IDs will need to make up ".$target_id_duration->format("H:i:s")."\n";

    $ids_playlist=roundOutWithIDs($conn, $target_id_duration);


    // Insert the ids playlist.  Calculated based on the ratio of songs_duration/id_duration    
    // echo "makePlaylist() best_candidate_playlist count is ".count($best_candidate_playlist)."\n";
    // echo "makePlaylist() ids_playlist count is ".count($ids_playlist)."\n";
        
    if (count($ids_playlist))
    {
        $id_frequency=round(count($best_candidate_playlist)/count($ids_playlist));
    }

    else{$id_frequency=0;}

    echo "makePlaylist() IDs will play every ".$id_frequency."\n";

    return intersperse($best_candidate_playlist,$ids_playlist, $id_frequency); 

    // if ($id_frequency>=2) 
    // {
    //     return intersperse($best_candidate_playlist,$ids_playlist, $id_frequency); 
    // }
    // else 
    // {
    //     echo "makePlaylist() Skipping ID additions; frequency of <2 not allowed\n";
    //     return($best_candidate_playlist);  // Return playlist without any IDs.
    // }

    
}


/******************************************/
// FUNCTION: roundOutWithIDs ()
// DESCRIPTION: 
// Makes a list of IDs that comes out close to the desired duration
//
// TAKES:
// Takes: DB connection object, $target_duration as a DateTime object
// 
// RETURNS:
// Returns: array of files infos
/******************************************/

function roundOutWithIDs($conn, $target_duration){

    $iterations=250;  // Number of attempts to make, increase for better quality
    $files = array();
    $test_playlist = array();
    $best_candidate_playlist = array();
    $best_candidate_duration = new DateTime('00:00');
    $loop_count=0;
    

    $target_durationString =  $target_duration->format("H:i:s");

    echo "roundOutWithIDs() Target IDs duration is ". $target_durationString ."\n";

    while (++$loop_count<$iterations) 
    {
        $duration = new DateTime('00:00'); // Interval of zero
        $testDuration =  new DateTime('00:00');  // Interval of zero        
        $files = array();
        $playlist = array();

        // Grab a list of 10 files from the database
        // (This can be any number, but must be at least as many will be in a typical show.)
        $query = "SELECT * FROM cc_files WHERE genre='ID' AND cc_files.file_exists=true  ORDER BY random()  LIMIT 10";   
        $result = pg_query($conn, $query);
        
        while ($row = pg_fetch_array($result)) {
            $files[] = $row;
            
        }
            
        if (count($files) == 0){
            echo "There are no songs in the library. Could not choose random song.";
            //exit(1);
        }


        // Truncate the list to one song before the target duration
        foreach($files as $file){            
            $songlengthDI = getDateInterval($file["length"]);

            // Test to see if this will put us over\
            $testDuration = clone ($duration);

            $testDuration->add($songlengthDI);
            if ($testDuration > $target_duration) 
            {
                break;
            }

            else 
                {
                    $duration = clone($testDuration);
                    $playlist[] = $file;  // Add this file to the playlist
                }
        }   // End of foreach file


        // Compare to the best candidate
        if ($duration >= $best_candidate_duration)
        {

            // $best_candidate_durationDT=new DateTime("00:00");
            // $differenceDI=$date_diff($best_candidate_duration,$duration)
            // $differenceDT=new DateTime("00:00");
            // $differenceDT->add($differenceDI);
            // if ($difference)

            // If this was better, store this playlist and duration as best_candidate
            $best_candidate_playlist=$playlist;
            $best_candidate_duration=clone($duration);
        }
        
        //echo "Trial ".count($playlist)." songs, duration ".$duration->format("H:i:s")."\n";

        if ($duration == $target_duration) {echo "roundOutWithIDs() nailed the ID playlist in ".$loop_count." tries!\n"; break;}
    }

    echo "roundOutWithIDs()  Best: ".count($best_candidate_playlist)." IDs, duration ".$best_candidate_duration->format("H:i:s")."\n";

    //print_r($best_candidate_playlist);

    
    return $playlist;
    
    

    
}

/******************************************/
// FUNCTION: getIDsFromCcFiles ()
// DESCRIPTION: 
// Retrieves all IDs (not IDs or podcasts) from the library at random.
//
// TAKES:
// DB connection 
// 
// RETURNS:
// Returns: array of files infos
/******************************************/


function getIDsFromCcFiles($conn){
    $query = "SELECT * FROM cc_files WHERE genre='ID' AND cc_files.file_exists=true  ORDER BY random()";    
    $result = pg_query($conn, $query);
    $files = array();
    while ($row = pg_fetch_array($result)) {
        $files[] = $row;
        
    }
        
    if (count($files) == 0){
        echo "There are no IDs in the library. Could not choose random ID.";
        //exit(1);
    }
    
    return $files;
    
}




/******************************************/
// FUNCTION: insertIntoCcShow ()
// DESCRIPTION: 
// Creates a new rotation show (No files or scheduling here) which will be associated with stuff later
//
// TAKES:
// DB connection 
// 
// RETURNS:
// Returns: A show ID which will be associated with scheduled things later
/******************************************/


function insertIntoCcShow($conn){
    
    $query = "INSERT INTO cc_show (name, url, genre, description, color, background_color) VALUES ('ROTATION', '', '', '', '', 000000)";
    echo $query.PHP_EOL;
    $result = query($conn, $query);

    $query = "SELECT currval('cc_show_id_seq');";
    $result = pg_query($conn, $query);
    if (!$result) {
      echo "Error executing query $query.\n";
      exit(1);
    }

    while ($row = pg_fetch_array($result)) {
      $show_id = $row["currval"];
    }
    
    return $show_id;
    
}




/******************************************/
// FUNCTION: insertIntoCcShowInstances ()
// DESCRIPTION: 
// Schedules the new rotation show 
//
// TAKES:
// DB connection, Show ID of the new show, start time and end time (as DateTime objects)
// 
// RETURNS:
// Returns: A show ID which will be associated with scheduled things later
/******************************************/


function insertIntoCcShowInstances($conn, $show_id, $starts, $ends){
    
    
    $nowDateTime = new DateTime("now", new DateTimeZone("UTC"));

    // Convert DateTime Objects to strings for database insertion
    $nowString = $nowDateTime->format("Y-m-d H:i:s");
    $startsString = $starts->format("Y-m-d H:i:s");
    $endsString = $ends->format("Y-m-d H:i:s");
  
    $columns = "(starts, ends, show_id, record, rebroadcast, instance_id, file_id, time_filled,created, last_scheduled, modified_instance)";
    $values = "('$startsString', '$endsString', $show_id, 0, 0, CURRVAL('cc_show_instances_id_seq'),null, TIMESTAMP '$endsString' - TIMESTAMP '$startsString', '$nowString','$nowString', 'f')";
    $query = "INSERT INTO cc_show_instances $columns values $values ";
    
    echo $query.PHP_EOL;
     
    $result = query($conn, $query);
    

    $query = "SELECT currval('cc_show_instances_id_seq');";
    $result = pg_query($conn, $query);
    if (!$result) {
      echo "Error executing query $query.\n";
      exit(1);
    }

    while ($row = pg_fetch_array($result)) {
      $show_instance_id = $row["currval"];
    }
    
    return $show_instance_id;
}


/******************************************/
// FUNCTION: insertIntoCcSchedule ()
// DESCRIPTION: 
// This function schedules an entire list of songs 
//
// TAKES:
// DB connection, Array of files infos, Show Instance ID of the scheduled show, 
// start time and end time (as DateTime objects)
// 
// RETURNS: Nothing.
/******************************************/



function insertIntoCcSchedule($conn, $files, $show_instance_id, $p_starts, $p_ends){
    $columns = "(starts, ends, file_id, clip_length, fade_in, fade_out, cue_in, cue_out, media_item_played, instance_id)";
    
    $starts = $p_starts->format("Y-m-d H:i:s");
    
    foreach($files as $file){

        $endsDateTime = new DateTime($starts, new DateTimeZone("UTC"));
        $lengthDateInterval = getDateInterval($file["length"]);
        $endsDateTime->add($lengthDateInterval);
        $ends = $endsDateTime->format("Y-m-d H:i:s");

        $values = "('$starts', '$ends', $file[id], '$file[length]', '00:00:00', '00:00:00', '00:00:00', '$file[length]', 'f', $show_instance_id)";
        $query = "INSERT INTO cc_schedule $columns VALUES $values";
        echo $query.PHP_EOL;
        
        $starts = $ends;
        $result = query($conn, $query);        

        $query  = "UPDATE cc_files SET is_scheduled = 'true' WHERE id = '$file[id]'";
        $result = query($conn, $query);
    }
}






/******************************************/
// FUNCTION: getEndTime_1hr ()
// DESCRIPTION: 
// Tries to determine a suitable end time for a rotation block 
// Specifically, the start time (UTC) of either the next top of the hour,
// or the beginning of the next scheduled show, whichever comes first.
//
// TAKES:
// DB connection, Array of files infos, Show Instance ID of the scheduled show, 
// start time and end time (as DateTime objects)
// 
// RETURNS: 
// a suitable end time for a rotation block as a DateTime object
/******************************************/

function getEndTime_1hr($conn){


    // Determine the minutes to the next scheduled show start
    $query = "
           SELECT ROUND(EXTRACT(EPOCH FROM starts-now() at time zone 'utc')/60) AS min_to_next_show FROM cc_show_instances                   
           WHERE (starts - now() at time zone 'utc')> INTERVAL '1 min' ORDER BY starts ASC
           LIMIT 1 ;
    ";
   

    $result = pg_query($conn, $query);

    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 

    $min_to_next_showInt=0;

    while ($row = pg_fetch_array($result)) {
      $min_to_next_showInt = $row["min_to_next_show"];
    }
    
    



    // Determine the time to the top of the next hour

    
    $query = "
            SELECT 60-date_part ('minute', now() at time zone 'utc') AS dbresult;
    ";

    $result = pg_query($conn, $query);
    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 
    $row = pg_fetch_array($result);
    $min_to_hour_topInt=$row["dbresult"];

    // This is problematic when running at 1 minute before the top of the hour.
    // The "next top of the hour" is determined to be upcoming one 1 min. from now,
    // And the effect is the creation of show with negative or no duration
    // i.e. 13:00:02 - 13:00:00
    // So if the result is <2, we will add 60
    if ($min_to_hour_topInt<2) {$min_to_hour_topInt+=60;}



    // Determine the next top of the hour to be
    $query = "
           SELECT date_part ('hour', now() at time zone 'utc') + 1 AS dbresult;
    ";
   

    $result = pg_query($conn, $query);
    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 
    $row = pg_fetch_array($result);
    $next_hourInt=$row["dbresult"];


    echo "Minutes to next show: $min_to_next_showInt\n";
    echo "Minutes to next hour: $min_to_hour_topInt\n";



    // Compare the two
    // If there are no future shows, min_to_next_show is 0, which is problematic, so avoided
    if (($min_to_next_showInt) && ($min_to_next_showInt <= $min_to_hour_topInt)) {
        // Return the start time of the next show
        return getStartOfNextShow($conn);
    }


    else 
    {
        // Return the top of the next hour
        // To avoid a buttload of work, we are just adding the # of minutes to now().  We are also rounding off seconds to get the real top.  Hacky.

        // Get it as a time stamp string
        $query = "
            SELECT date_trunc('minute', now() at time zone 'utc' + INTERVAL '30 second' + INTERVAL '".$min_to_hour_topInt." min') AS dbresult;
        ";

        $result = pg_query($conn, $query);
        if (!$result) {
           echo "Error executing query $query.\n";
           exit(1);
       } 
       $row = pg_fetch_array($result);
       $min_to_hour_topString=$row["dbresult"];

       // Convert it to a DateTime
       $min_to_hour_topDateTime = new DateTime($min_to_hour_topString, new DateTimeZone("UTC"));
       
       return $min_to_hour_topDateTime;

       


   }

}



/*****************************************/
/* FUNCTION: getStartOfNextShow() (formerly getEndTime()
/* AUTHOR: Scott McGrath
/* DESCRIPTION:
// The beginning of the next scheduled show
// RETURNS: 
// Beginning of the next show as a DateTime
/******************************************/

function getStartOfNextShow($conn){
    $query = "
           SELECT starts AS start_of_next_show FROM cc_show_instances                   
           WHERE (starts - now() at time zone 'utc')> INTERVAL '1 min' ORDER BY starts ASC
           LIMIT 1 ;
    ";
    

    $result = pg_query($conn, $query);

    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 

    $start_of_next_show=0;

    while ($row = pg_fetch_array($result)) {
      $start_of_next_show = $row["start_of_next_show"];
    }
    
    // Convert string to a DT object
    $start_of_next_showDateTime = new DateTime($start_of_next_show, new DateTimeZone("UTC"));
    return $start_of_next_showDateTime;

}



/*****************************************/
/* FUNCTION: gapComing()
/* AUTHOR: Scott McGrath
/* Returns true if either a.) we are not currently in a show, 
/* or b.) the current show ends within 1 minute AND
/* there are no shows scheduled to start after it
/******************************************/

function gapComing($conn){

    // If we are currently in a show that ends more than 1 min from now, return false
	//  NOTE: This query has been known to occasionally return no rows despite being in a show, causing a second show block to run simultaneously.
	// In an attempt to alleviate this, it now looks to see if the start time is now, not just before now.
    $query = "SELECT * FROM cc_show_instances WHERE starts <= now() AT time zone 'utc' AND ends > (now() AT time zone 'utc' + INTERVAL '1 min') AND modified_instance='f';";
    $result = pg_query($conn, $query);

    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 

    if (pg_num_rows($result)) {
        echo "A current show seems to be in progress. Show ID: ";
        while ($row = pg_fetch_array($result)) {
            $instance_show_id=$row["show_id"];
            $instance_id=$row["id"];
            echo $instance_show_id.", ";
            echo "Show instance ID: $instance_id\n";
        }

        return 0;
    }



    // We are not currently in a show.
    // If there is a show starting within 1 min from now, return false
    $query = "select * from cc_show_instances where starts > now() at time zone 'utc' AND starts < (now() at time zone 'utc' + INTERVAL '1 min') AND modified_instance='f';";    
    $result = pg_query($conn, $query);

    if (!$result) {
       echo "Error executing query $query.\n";
       exit(1);
    } 

    if (pg_num_rows($result)) {        
        echo "We are not currently in a show, but one is starting in a minute.\n";
        return 0;
    }


    // At this point we can be pretty sure there is a gap here already.  We'll return 2, just to indicate that we can expedite the start of a rotation show.
    return 2;

}



/******************************************/
// FUNCTION: intersperse()
// DESCRIPTION: Intersperses a given list of station IDs into a list of songs
// according to an (optional) given "frequency".  Frequency in this case is how many songs
// should play between IDs.  If the given list of IDs is too short, it will maintain the frequency
// as many IDs as possible, and subsequently only songs will be added.
//
// TAKES:
// $songs - an array of song infos 
// $ids - array of ID infos, 
// $frequency - a optional frequency of ids to songs. If frequency is not provided, it defaults to 5.
//              IF FREQUENCY IS 0, it is a special case and no IDs will play.
//              IF FREQUENCY IS 1, it is a special case and is assumed there is only a single ID.
// 
// RETURNS: array of files infos
/******************************************/

function intersperse($songs, $ids, $frequency = 5) 
{
    $result = array();
    $added = 0;
    $id_count=count($ids)-1;



    // Handle the 0 frequency case
    if ($frequency==0)
    {
        // Return only the songs
        return $songs;
    }

    // Handle the single ID case
    if ($frequency==1)
    {
        $result=$songs;
        // Prepend a single ID to the front of the array
        array_unshift($result,$ids[0]);
        echo "intersperse() Added an ID\n";
        return $result;
    }

    $frequency++;  // Fence post error correction

    echo "intersperse() ".count($songs)." songs and ".count($ids)." IDs\n";


    for($i = 0; $i < (count($songs)+$added); $i++)
    {
        //echo "intersperse() Count of songs+added is now ".count($songs)+$added."\n";
        //echo "$i,$added\n";

        if($i%$frequency == 0)  // If it's a slot for ID
        { 
            //$result[$i] = $ids[rand(0,count($ids)-1)]; 
            //Treat the ID array as fixed. Previously randomized, and no repeating allowed.
            if ($id_count>=0)   // If there are IDs left
            {
                $result[$i] = $ids[$id_count--];
                $added++;
                echo "intersperse() Added an ID\n";
            }

            else 
            {
                $result[$i] = $songs[$i-$added];
                echo "intersperse() Added song because we are finished with IDs";
                echo $i-$added;
                echo "\n";
            }


        }

        else 
            {
                $result[$i] = $songs[$i-$added];
                echo "intersperse() Added song ";
                echo $i-$added;
                echo "\n";
            }
    }

    return $result;
}





/*****************************************/
/* FUNCTION: lastplayed()
/* AUTHOR: Scott McGrath
/* Determines the time the last scheduled show ended.
/******************************************/


function lastplayed($conn){
$query = "SELECT 
                    cc_schedule.ends
                FROM cc_schedule
                    ORDER BY   cc_schedule.ends DESC 
                                LIMIT 1 
                ";
                  $result = pg_query($conn, $query);
                  if (!$result) {
                             echo "Error executing query $query.\n";
                             exit(1);
                        }

                  while ($row = pg_fetch_array($result)) {
                         $files[] = $row;
                             
                                    $last_sched=$row["ends"];
                                    
                                    return  $last_sched;
                                    }
 
}



/******************************************/
// FUNCTION: getDateInterval ()
// DESCRIPTION: Conversion function, returns a given length of time as a DateInterval
//
// TAKES:
// Desired length of time as a string
// 
// RETURNS: length of time as a DateInterval Object
/******************************************/



function getDateInterval($interval){
    list($length,) = explode(".", $interval);
    list($hour, $min, $sec) = explode(":", $length);
    return new DateInterval("PT{$hour}H{$min}M{$sec}S");
}





/******************************************/
// FUNCTION: addIntervals ()
// DESCRIPTION:  Stupid function I had to write because
// DateInterval objects apparently don't have the technology
// be added.
//
// TAKES: 2 DateInterval Objects
// 
// RETURNS: Result as a DateInterval Object
/******************************************/


function intervalAdd($interval1,$interval2)
{

    $e = new DateTime('00:00');
    $f = clone $e;
    $e->add($interval1);
    $e->add($interval2);    

    return $f->diff($e);
}



/******************************************/
// FUNCTION: intervalGreaterThan ()
// DESCRIPTION:  Stupid function I had to write because
// DateInterval objects apparently don't have the technology
// be added.
//
// TAKES: 2 DateInterval Objects
// 
// RETURNS: boolean TRUE if $interval1 is greater than $interval2
// boolean FALSE if $interval1 is less than $interval2
/******************************************/


function intervalGreaterThan($interval1,$interval2)
{

    $e = new DateTime('00:00');
    $f = clone $e;
    $e->add($interval1);
    $f->add($interval2);

    return $e>$f;
}




// This awful little routine calculates a fraction of a DateTime
// TAKES: DateTime multiplicand, float multiplier
function multiplyDateTimeBy($DateTimeObj,$fraction)
{

    $duration_seconds=strtotime("1970-01-01 ".$DateTimeObj->format("H:i:s")." UTC");
    //echo "multiplyDateTimeBy() duration is ".$duration_seconds."\n";
    $multiply = round($duration_seconds * $fraction);
    //echo "multiplyDateTimeBy() seconds will be ".$multiply."\n";
    $retDateTimeObj=new DateTime(gmdate("H:i:s",$multiply));
    return $retDateTimeObj;
}





/******************************************/
// FUNCTION: getTimeString ()
// DESCRIPTION: Conversion function, returns a DateTime object as a timestamp string 
//
// TAKES: DateTime Object
// 
// RETURNS: timestamp as a string
/******************************************/

function getTimeString($DateTime){
    return $DateTime->format("Y-m-d H:i:s");    
}





// Boring function to execute db queries

function query($conn, $query){
    $result = pg_query($conn, $query);
    if (!$result) {
        echo "Error executing query $query.\n";
        exit(1);
    }
    
    return $result;
    
    
}

// Let's airtime know that stuff has changed
function rabbitMqNotify(){
    $ini_file = parse_ini_file("/etc/airtime/airtime.conf", true);
    $url = "http://localhost/api/rabbitmq-do-push/format/json/api_key/".$ini_file["general"]["api_key"];

    echo "Contacting $url".PHP_EOL;
    $ch = curl_init($url);
    curl_exec($ch);
    curl_close($ch);    
}


