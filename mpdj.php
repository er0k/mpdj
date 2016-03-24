<?php

class mpdj
{
    const REFRESH = 3;
    const LOG = '/var/log/mpd/mpdj.log';
    const JSON_STATUS = '/tmp/mpd-status.json';
    const JSON_SONG = '/tmp/mpd-song.json';
    const ICECAST_URL = 'http://deb.r0k:8001/';
    const MUSIC_DIR = '/Music/';

    private $mpd;
    private $status;
    private $stats;
    private $db;
    private $dbUpdated = 0;
    private $statsUpdated = 0;
    private $songId;
    private $currentSong;
    private $listeners = -1;

    function __construct(MPD $mpd)
    {
        $this->mpd = $mpd;
    }

    public function start()
    {
        $this->refreshStats();

        while (true) {
            $this->refreshStatus();
            $this->checkForError();
            
            if ($this->hasSongChanged()) {
                $this->getCurrentSong();
            }

            if ($this->isLastSong()) {
                $this->addRandomSong();
                $this->cleanUpPlaylist();
            }

            if (!$this->isPlaying()) {
                $this->mpd->play();
            }

            $this->setCrossfade();

            sleep(self::REFRESH);
        }
    }

    private function setCrossfade()
    {
        if ($this->status['playlistlength'] == 3 && !isset($this->status['xfade'])) {
            try {
                $this->mpd->crossfade(10);
            } catch (MPDException $e) {
                $this->logError($e->getMessage());
            }
        }

        return $this;
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
        $this->status['listeners'] = $this->getListeners();
        $this->writeJsonFile(self::JSON_STATUS, $this->status);
        print_r($this->status);

        return $this;
    }

    // @todo : this is super ghetto
    private function getListeners()
    {
        $ch = curl_init(self::ICECAST_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
        ));
        $response = curl_exec($ch);
        curl_close($ch);

        $lines = explode("\n", $response);
        $line = $lines[48];
        preg_match('/[0-9*]/', $line, $matches);
        $this->listeners = isset($matches[0]) ? $matches[0] : $this->listeners;

        return $this->listeners;
    }

    private function writeJsonFile($file = '', $contents = array())
    {
        if (empty($file) || empty($contents)) {
            return;
        }

        file_put_contents($file, json_encode($contents));

        return $this;
    }

    private function refreshStats()
    {
        // don't care so much about the most current stats
        // only check them once an hour
        $statsTimeout = 3600;
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

        $randomSong = $db[array_rand($db)];
        while (!$this->isFile($randomSong)) {
            $randomSong = $db[array_rand($db)];
        }

        echo "$randomSong\n";

        return $randomSong;
    }

    private function isFile(&$song)
    {
        if (is_file(self::MUSIC_DIR . $song)) {
            return true;
        }

        if (
            is_array($song)
            && isset($song['file'])
            && is_file(self::MUSIC_DIR . $song['file'])
        ) {
            $song = $song['file'];
            return true;
        }

        return false;
    }

    private function addRandomSong($num = 1)
    {
        for (; $num > 0; $num--) {
            try {
                $this->mpd->add($this->getRandomSong());
            } catch (MPDException $e) {
                // better luck next time...
                $this->logError($e->getMessage());
            }
        }

        return $this;
    }

    private function cleanUpPlaylist()
    {
        if ($this->status['song'] >= 1) {
            echo "cleaning up playlist\n";
            $previousSong = $this->status['song'] - 1;
            try {
                // only keep 1 previous song in the playlist
                $this->mpd->delete("0:$previousSong");
            } catch (MPDException $e) {
                $this->logError($e->getMessage());
            }
        }

        return $this;
    }

    private function getCurrentSong()
    {
        $this->currentSong = $this->mpd->currentsong();
        print_r($this->currentSong);
        $this->writeJsonFile(self::JSON_SONG, $this->currentSong);

        return $this->currentSong;
    }

    private function hasSongChanged()
    {
        $lastSongId = $this->songId;
        $this->songId = $this->status['songid'];

        if ($this->status['songid'] != $lastSongId) {
            return true;
        }

        return false;
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
