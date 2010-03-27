#!/usr/bin/python2.5
#
# backup.py:
# Backup up data from live site.
#
# Copyright (c) 2010 UK Citizens Online Democracy. All rights reserved.
# Email: francis@mysociety.org; WWW: http://www.mysociety.org/
#

import sys
import csv
import os

sys.path.append("../")
import django.utils.simplejson as json

# Parameters
URL="http://theyworkforyouelection.appspot.com/remote_api"
EMAIL="election@theyworkforyou.com"
BACKUP_FILE="/data/backups/dailydb/theyworkforyouelection.googleappengine.sqlite3"

# Feed it to the uploader
os.chdir('/') # put temporary files here
cmd = '''bulkloader.py --dump --log_file=/tmp/bulkloader-backup-log --db_filename=skip --url=%s --filename=%s --app_id=theyworkforyouelection --email="%s"''' % (URL, BACKUP_FILE, EMAIL)
os.system(cmd)

# Compress it
os.system("gzip --force " + BACKUP_FILE)

