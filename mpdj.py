#!/usr/bin/env python

import gc
import icecast
import hashlib
import json
import mpd
import os
import pprint
import random
import threading
import time

class mpdj:

    key_file = '/home/er0k/.www/keys.json'
    json_file = '/tmp/mpdj.json'
    bad_log = '/etc/mpdj/bad'
    nope_log = '/etc/mpdj/nope'
    music_dir = '/Music/'
    status = {}
    song = {}
    playlist = {}
    stats = {}
    streams = {}
    listeners = {"count": -1}
    dbUpdatedAt = 0
    mpd = None
    db = None
    error = None
    keys = None

    def __init__(self):
        self.keys = self.getKeys()
        self.mpd = mpd.MPDClient()
        self.mpd.connect(self.getHost(), self.getPort())

    def __del__(self):
        self.mpd.close()
        self.mpd.disconnect()

    def getKeys(self):
        if self.keys is None:
            with open(self.key_file) as keyFile:
                self.keys = json.load(keyFile)
        return self.keys

    def getHost(self):
        if 'MPD_HOST' in os.environ:
            return os.environ['MPD_HOST']
        return 'localhost'

    def getPort(self):
        if 'MPD_PORT' in os.environ:
            return os.environ['MPD_PORT']
        return 6600

    def spin(self):
        """ mister dj spins the record"""
        self.refresh()
        threading.Thread(target = self.tableOne).start()
        threading.Thread(target = self.tableTwo).start()
    
    def refresh(self):
        self.updateStats()
        self.updateStatus()
        self.updateSong()
        self.updatePlaylist()
        self.updateListeners()
        self.checkForError()
        self.checkForStreams()
        self.writeJson()

    def tableOne(self):
        while True:
            changed = self.mpd.idle()
            if changed:
                self.updateStatus()
                self.updateSong()
                self.checkForError()
                if 'playlist' in changed:
                    self.updatePlaylist()
                self.checkForStreams()
                if self.isLastSong():
                    self.addRandomSong()
                    self.cleanUpPlaylist()
                self.setCrossfade()
                if not self.isPlaying():
                    self.mpd.play()
                self.writeJson()
                gc.collect()

    def tableTwo(self):
        while True:
            self.updateListeners()
            time.sleep(5)

    def writeJson(self):
        print "writing data to json file!"
        output = {
            "song": self.song,
            "stats": self.stats,
            "status": self.status,
            "listeners": self.listeners,
            "playlist": self.playlist
        }

        with open(self.json_file, 'w') as jsonfile:
            json.dump(output, jsonfile)

    def updateStats(self):
        self.stats = self.mpd.stats()

    def updateSong(self):
        self.song = self.mpd.currentsong()

    def updateStatus(self):
        self.status = self.mpd.status()

    def updatePlaylist(self):
        self.playlist = self.mpd.playlistinfo()

    def isLastSong(self):
        if 'song' not in self.status:
            return True
        if (int(self.status['song']) == (int(self.status['playlistlength']) - 1)):
            return True
        return False

    def addRandomSong(self, num = 1):
        print "adding %s random song(s)" % num
        while num > 0:
            randomSong = self.getRandomSong()
            print randomSong
            self.mpd.add(randomSong)
            num = num - 1

    def getRandomSong(self):
        self.db = self.getDb()
        randomSong = ''
        while not self.isFile(randomSong) and not self.isGood(randomSong):
            randomSong = random.choice(self.db)
        return randomSong['file']

    def getDb(self):
        self.updateStats()
        if (self.db is None or int(self.stats['db_update']) > self.dbUpdatedAt):
            print "getting db..."
            self.db = self.mpd.listall()
            self.dbUpdatedAt = time.time()
        return self.db

    def isGood(self, song = ''):
        if not song:
            return False
        with open(self.bad_log) as bad:
            if song in bad.readline():
                return False
        with open (self.nope_log) as nope:
            if song in nope.readline():
                return False
        return True

    def isFile(self, song = ''):
        if 'file' not in song:
            return False
        if (os.path.isfile(self.music_dir + song['file'])):
            return True
        return False

    def isPlaying(self):
        if self.status['state'] == 'play':
            return True
        return False

    def setCrossfade(self):
        if (int(self.status['playlistlength']) == 3 and 'xfade' not in self.status):
            self.mpd.crossfade(10)

    def cleanUpPlaylist(self):
        if int(self.status['song']) >= 1:
            print 'cleaning up playlist'
            previousSong = int(self.status['song']) - 1
            self.mpd.delete("0:%s" % previousSong)

    def checkForError(self):
        self.error = self.status['error'] if 'error' in self.status else None
        if self.error is not None:
            self.logError()
            self.recoverFromError()

    def logError(self, errorMsg = ''):
        errorMsg = self.error if not errorMsg else errorMsg
        print "ERROR! %s" % errorMsg
        with open(self.bad_log, 'a') as logfile:
            logfile.write(errorMsg + "\n")

    def recoverFromError(self):
        if self.error:
            playlist = self.mpd.playlistinfo()
            for pos, song in enumerate(playlist):
                print pos, song['file']
                if song['file'] in self.error:
                    print "deleting position %s" % pos
                    self.mpd.delete(pos)
                    break
            self.addRandomSong()
            self.mpd.play(pos)
            self.clearError()

    def clearError(self):
        self.mpd.clearerror()
        self.error = None

    def checkForStreams(self):
        self.checkPlaylistForStreams()
        self.checkCurrentSongForStream()

    def checkPlaylistForStreams(self):
        for song in self.playlist:
            # if we don't have a modified timestamp, assume it is a stream instead of a file
            # I don't currently know a better way to check
            # maybe song length? or look for http in file?
            if 'last-modified' not in song:
                streamInfo = self.getStreamInfo(song)
                if streamInfo:
                    self.setPlaylistStreamInfo(int(song['pos']), streamInfo)

    def getStreamInfo(self, song):
        if song['id'] not in self.streams:
            file = '/tmp/%s.json' % hashlib.md5(song['file']).hexdigest()
            if not os.path.isfile(file):
                return False
            # need to clean up these temp files probably...
            with open(file, 'r') as streamFile:
                self.streams[song['id']] = json.load(streamFile)
        return self.streams[song['id']]

    def setPlaylistStreamInfo(self, pos, info):
        self.playlist[pos]['name'] = info['info']['title']
        self.playlist[pos]['time'] = info['info']['length']
        self.playlist[pos]['file'] = "https://www.youtube.com/watch?v=%s" % info['id']

    def setCurrentSongStreamInfo(self, info):
        self.song['name'] = info['info']['title']
        self.song['time'] = info['info']['length']
        self.song['file'] = "https://www.youtube.com/watch?v=%s" % info['id']

    def checkCurrentSongForStream(self):
        if 'last-modified' not in self.song:
            streamInfo = self.getStreamInfo(self.song)
            if streamInfo:
                self.setCurrentSongStreamInfo(streamInfo)


    def updateListeners(self):
        self.previousListenerCount = self.listeners['count']
        iceAuth = (self.keys['icecast'][0], self.keys['icecast'][1])
        ice = icecast.icecast(iceAuth)
        self.listeners = ice.getListeners()
        if self.previousListenerCount != self.listeners['count']:
            print "count: %s" % self.listeners['count']
            self.writeJson()

        
mpdj().spin()
