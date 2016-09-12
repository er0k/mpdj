#!/usr/bin/env python
import requests
import xml.etree.ElementTree as et

class icecast:
    """parse icecast XML stats and put them in an object"""
    
    host = 'localhost'
    port = 8001
    mount = '/stream.ogg'

    def __init__(self, auth):
        self.auth = auth
        self.url = "http://%s:%s/admin/listclients?mount=%s" % (
            self.host, self.port, self.mount)

    def getListeners(self):
        stats = requests.get(self.url, auth=(self.auth))
        icestats = et.fromstring(stats.text)
        count = icestats[0].find('Listeners').text
        listeners = {"count": int(count)}
        clients = []
        for listener in icestats[0].findall('listener'):
            client = {}
            for node in listener:
                client[node.tag] = node.text
            clients.append(client)
        listeners['clients'] = clients
        return listeners

