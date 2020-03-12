# Airtime-Rotation
This is an implementation of "auto-dj" or automatic "rotation" for Sourcefabric Airtime 2.5. It has also been tested working on Libretime 3.0.  The purpose is to provide an "auto-dj" function, playing random selections during times where there is no scheduled show content.  

It includes code contributed by Voisses Tech on the sourcefabric.org forum at:
https://forum.sourcefabric.org/discussion/18336/autodj-script-using-php-2-1-5-6-solution-you-were-waiting-on-no-ls_script-modification-need

If there is no show instance on the calendar in the next minute, it will create a block of random selections from the library that will closely fill the amount of time until the next scheduled event.  A show called "ROTATION" containing this block of content will appear on the calendar. Everything else works as though a human had created it.


# Installation

The simplest way to get this going is:
* Copy rotation.php to /usr/local/bin
* Edit the pg_connect() line near the top for your Airtime Postgresql db user and password (default that ships with Airtime is: airtime, airtime)
* Edit root's cron to run it every minute to see if there is a scheduling gap:
`* * * * * /usr/bin/php /usr/local/bin/rotation.php >> /root/rotation.log 2>&1`

# Notes
The Airtime library "genre" field is used to prevent certain library selections from being included as part of a rotation block.  The following tags are excluded (case sensitive!):
* Show
* RotEx
* Rotex
* Podcast

# Known issues
If playout is occuring when a rotation block is generated, there is a brief (.5 second or so) silence gap heard when Liquidsoap is restarted. 
