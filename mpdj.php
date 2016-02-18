<?php

class mpdj
{
    const REFRESH = 3;
    const LOG = '/var/log/mpd/mpdj.log';

    private $mpd;
    private $status;
    private $stats;
    private $db;
    private $dbUpdated = 0;
    private $statsUpdated = 0;

    function __construct(MPD $mpd)
    {
        $this->mpd = $mpd;
    }

    public function start()
    {
        $this->refreshStats();

        while (true) {
            $this->refreshStatus()->checkForError();

            if ($this->isLastSong()) {
                $this->addRandomSong()->cleanUpPlaylist();
            }

            if (!$this->isPlaying()) {
                $this->mpd->play();
            }

            sleep(self::REFRESH);
        }
        
    }

    private function checkForError()
    {
        $this->error = isset($this->status['error']) ? $this->status['error'] : null;
        if ($this->error) {
            $this->logError()->recoverFromError();
        }

        return $this;
    }

    private function logError($errorMsg = '')
    {
        $errorMsg = empty($errorMsg) ? $this->error : $errorMsg;
        error_log($errorMsg . "\n", 3, self::LOG);
        echo "$errorMsg\n";

        return $this;
    }

    private function recoverFromError()
    {
        if ($this->error) {
            try {
                    $playlist = $this->mpd->playlistinfo();
            } catch (MPDException $e) {
                $playlist = array();
                $this->logError($e->getMessage());
            }
            
            foreach ($playlist as $pos => $song) {
                if (strpos($this->error, $song['file']) !== false) {
                    try {
                        $this->mpd->delete($pos);
                    } catch (MPDException $e) {
                        $this->logError($e->getMessage());
                    break;
                    }
                    $this->addRandomSong();
                    try {
                        $this->mpd->play($pos);
                    } catch (MPDException $e) {
                        // couldn't resume the last position in the playlist,
                        // should auto recover...
                        $this->logError($e->getMessage());
                    }
                    break;
                }
            }
            $this->clearError();
        }

        return $this;
    }

    private function clearError()
    {
        try {
            $this->mpd->clearerror();
        } catch (MPDException $e) {
            $this->logError($e->getMessage());
        }
        $this->error = null;

        return $this;
    }

    private function refreshStatus()
    {
        $this->status = $this->mpd->status();
        print_r($this->status);

        return $this;
    }

    private function refreshStats()
    {
        // don't care so much about the most current stats
        // only check them once an hour
        $statsTimeout = 60 * 60;
            if ((time() - $statsTimeout) > $this->statsUpdated) {
            $this->stats = $this->mpd->stats();
            $this->statsUpdated = time();
            print_r($this->stats);
        }

    return $this;
    }

    // @todo : check song against error log?
    // @todo : keep track of songs played in last X  hours,
    //         if in the list, get a new random song
    private function getRandomSong()
    {
        $db = $this->getDb();

        $randKey = array_rand($db);
        while (is_array($db[$randKey])) {
            $randKey = array_rand($db);
        }

        $randomSong = $db[$randKey];

        echo "$randomSong\n";

        return $randomSong;
    }

    private function addRandomSong()
    {
        try {
            $this->mpd->add($this->getRandomSong());
        } catch (MPDException $e) {
            // better luck next time...
            $this->logError($e->getMessage());
        }

        return $this;
    }

    private function cleanUpPlaylist()
    {
        if ($this->status['song'] >= 1) {
            echo "cleaning up playlist\n";
            $previousSong = $this->status['song'] - 1;
        try {
            $this->mpd->delete("0:$previousSong");
        } catch (MPDException $e) {
            $this->logError($e->getMessage());
        }
    }

        return $this;
    }

    private function isLastSong()
    {
        if ($this->status['song'] == ($this->status['playlistlength'] - 1)) {
            return true;
        }
        return false;
    }

    private function isPlaying()
    {
        if ($this->status['state'] == 'play') {
            return true;
        }
        return false;
    }

    private function getDb()
    {
        $this->refreshStats();
    
        if (!$this->db || $this->stats['db_update'] > $this->dbUpdated) {
            echo "getting db from mpd...";
            $this->db = $this->mpd->listall();
            echo "ok\n";
            $this->dbUpdated = time();
        }

        return $this->db;
    }
}

