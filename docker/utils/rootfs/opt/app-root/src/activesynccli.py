#!/bin/env python3

"""
activesynccli.py
    --host apps.kolabnow.com
    --devicetype WindowsOutlook15
    --deviceid windowsascli
    --user user@kolab.org --password Secret
    --verbose
    list --folder INBOX

# Dependencies

    dnf install libwbxml-devel
    pip install --global-option=build_ext --global-option="-I/usr/include/libwbxml-1.0/wbxml/" git+https://github.com/Apheleia-IT/python-wbxml#egg=wbxml

"""

import argparse
import base64
import http.client
import urllib.parse
import struct
import xml.etree.ElementTree as ET
import ssl
import time
import wbxml


# def track_memory_usage():
#     from pympler import muppy, summary
#     import pandas as pd
#     all_objects = muppy.get_objects()
#     sum1 = summary.summarize(all_objects)  # Prints out a summary of the large objects
#     summary.print_(sum1)  # Get references to certain types of objects
#     dataframes = [ao for ao in all_objects if isinstance(ao, pd.DataFrame)]
#     for d in dataframes:
#         print(d.columns.values)
#         print(len(d))


def decode_timezone(tz):
    decoded = base64.b64decode(tz)
    bias, standardName, standardDate, standardBias, daylightName, daylightDate, daylightBias = struct.unpack('i64s16si64s16si', decoded)
    print(f"  TimeZone bias: {bias}min")
    print(f"  Standard Name: {standardName.decode()}")
    year, month, day, week, hour, minute, second, millis = struct.unpack('hhhhhhhh', standardDate)
    print(f"  Standard Date: Year: {year} Month: {month} Day: {day} Week: {week} Hour: {hour} Minute: {minute} Second: {second} Millisecond: {millis}")
    print(f"  Standard Bias: {standardBias}min")
    print(f"  Daylight Name: {daylightName.decode()}")
    year, month, day, week, hour, minute, second, millis = struct.unpack('hhhhhhhh', daylightDate)
    print(f"  Daylight Date: Year: {year} Month: {month} Day: {day} Week: {week} Hour: {hour} Minute: {minute} Second: {second} Millisecond: {millis}")
    print(f"  Daylight Bias: {daylightBias}min")
    print()


def http_request(url, method, params=None, headers=None, body=None):
    """
        Perform an HTTP request.
    """

    # print(url)
    parsed_url = urllib.parse.urlparse(url)
    # print("Connecting to ", parsed_url.netloc)
    if url.startswith('https://'):
        conn = http.client.HTTPSConnection(parsed_url.netloc, 443, context = ssl._create_unverified_context())
    else:
        conn = http.client.HTTPConnection(parsed_url.netloc, 80)

    if params is None:
        params = {}

    if headers is None:
        headers = {
            "Content-Type": "application/x-www-form-urlencoded; charset=utf-8"
        }

    if body is None:
        body = urllib.parse.urlencode(params)

    # print("Requesting", parsed_url.geturl(), "From", parsed_url.netloc)
    conn.request(method, parsed_url.geturl(), body, headers)
    response = conn.getresponse()

    # Handle redirects
    if response.status in (301, 302,):
        # print("Following redirect ", response.getheader('location', ''))
        return http_request(
            urllib.parse.urljoin(url, response.getheader('location', '')),
            method,
            params,
            headers,
            body)

    if not response.status == 200:
        print("  ", "Status", response.status)
        print("  ", response.read().decode())

    return response


def basic_auth_headers(username, password):
    user_and_pass = base64.b64encode(
        f"{username}:{password}".encode("ascii")
    ).decode("ascii")

    return {
        "Authorization": "Basic {}".format(user_and_pass)
    }


def try_get(name, url, verbose, headers = None, body = None):
    response = http_request(
        url,
        "GET",
        None,
        headers,
        body
    )
    success = response.status == 200
    if not success:
        print(f"=> Error: {name} is not available")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", response.read().decode())

    return success


class ActiveSync:
    def __init__(self, options):
        self.host = options.host
        self.username = options.user
        self.password = options.password
        self.verbose = options.verbose

        if options.deviceid:
            self.deviceid = options.deviceid
        else:
            self.deviceid = 'v140Device'

        if options.devicetype:
            self.devicetype = options.devicetype
        else:
            self.devicetype = 'iphone'

        if hasattr(options, 'folder') and options.folder:
            self.folder = options.folder
        else:
            self.folder = None

        if hasattr(options, 'sync_key') and options.sync_key:
            self.sync_key = options.sync_key
        else:
            self.sync_key = 0

        if hasattr(options, 'folder_sync_key') and options.folder_sync_key:
            self.folder_sync_key = options.folder_sync_key
        else:
            self.folder_sync_key = 0

        if hasattr(options, 'poll') and options.poll:
            self.poll = options.poll
        else:
            self.poll = False

        if hasattr(options, 'profile') and options.profile:
            self.profile = options.profile
        else:
            self.profile = False


    def send_request(self, command, request, extra_args = None):
        if self.verbose:
            print(request)

        body = wbxml.xml_to_wbxml(request)

        headers = {
            "Host": self.host,
            **basic_auth_headers(self.username, self.password)
        }

        headers.update(
            {
                "Content-Type": "application/vnd.ms-sync.wbxml",
                'MS-ASProtocolVersion': "14.0",
            }
        )

        if extra_args is None:
            extra_args = ""

        profile = ""
        if self.profile:
            profile = "XDEBUG_TRIGGER=StartProfileForMe&"

        return http_request(
            f"https://{self.host}/Microsoft-Server-ActiveSync?{profile}Cmd={command}&User={self.username}&DeviceId={self.deviceid}&DeviceType={self.devicetype}{extra_args}",
            "POST",
            None,
            headers,
            body
        )


    def check(self):
        headers = {
            "Host": self.host,
            **basic_auth_headers(self.username, self.password)
        }

        response = http_request(
            f"https://{self.host}/Microsoft-Server-ActiveSync",
            "OPTIONS",
            None,
            headers,
            None
        )

        success = response.status == 200
        data = response.read().decode()
        if not success:
            print("=> Error: Activesync is not available")
        else:
            # Sanity check of the data
            assert response.getheader('MS-Server-ActiveSync', '')
            assert '14.1' in response.getheader('MS-ASProtocolVersions', '')
            assert 'FolderSync' in response.getheader('MS-ASProtocolCommands', '')

        if self.verbose or not success:
            print("  ", "Status", response.status)
            print("  ", data)

        return success


    def add(self):
        request = """
        <Add xmlns:default="uri:Email" xmlns:default1="uri:AirSyncBase">
            <Class>Tasks</Class>
            <ClientId>30858234-494C-42FB-9352-009FA305762B</ClientId>
            <ApplicationData>
                <Subject xmlns="uri:Tasks">{subject}</Subject>
                <Importance xmlns="uri:Tasks">1</Importance>
                <Categories xmlns="uri:Tasks"/>
                <Complete xmlns="uri:Tasks">0</Complete>
                <ReminderSet xmlns="uri:Tasks">0</ReminderSet>
                <Sensitivity xmlns="uri:Tasks">0</Sensitivity>
                <DueDate xmlns="uri:Tasks">2020-11-04T00:00:00.000Z</DueDate>
                <UTCDueDate xmlns="uri:Tasks">2020-11-03T23:00:00.000Z</UTCDueDate>
            </ApplicationData>
        </Add>
        """.replace('        ', '').replace('      ', '').replace('    ', '').replace('  ', '').replace('\n', '').format(
            subject="subject"
        )
        return request


    def do_sync(self, collection_id, sync_key = 0, upload_count = None):
        start = time.time();
        commands = ""

        if upload_count is not None:
            add_commands = ""
            for i in range(upload_count):
                add_commands = add_commands + self.add()
            commands = """
        <Commands>
            {add_commands}
        </Commands>
        """.replace('    ', '').replace('\n', '').format(add_commands=add_commands)

        request = """
        <?xml version="1.0" encoding="utf-8"?>
        <!DOCTYPE AirSync PUBLIC "-//AIRSYNC//DTD AirSync//EN" "http://www.microsoft.com/">
        <Sync xmlns="uri:AirSync" xmlns:AirSyncBase="uri:AirSyncBase" xmlns:Email="uri:Email" xmlns:Tasks="uri:Tasks" >
            <Collections>
                <Collection>
                    <SyncKey>{sync_key}</SyncKey>
                    <CollectionId>{collection_id}</CollectionId>
                    <DeletesAsMoves>0</DeletesAsMoves>
                    <DeletesAsMoves>0</DeletesAsMoves>
                    <WindowSize>512</WindowSize>
                    <Options>
                        <FilterType>0</FilterType>
                        <MIMESupport>2</MIMESupport>
                        <MIMETruncation>8</MIMETruncation>
                        <BodyPreference xmlns="uri:AirSyncBase">
                            <Type>4</Type>
                            <AllOrNone>1</AllOrNone>
                        </BodyPreference>
                    </Options>
                    {commands}
                </Collection>
            </Collections>
            <WindowSize>512</WindowSize>
        </Sync>
        """.replace('    ', '').replace('\n', '').format(collection_id=collection_id, sync_key=sync_key, commands=commands)

        response = self.send_request('Sync', request)

        assert response.status == 200

        data = response.read()
        if not data:
            if self.verbose:
                print("Empty response, no changes on server")
            return [sync_key, False]

        result = wbxml.wbxml_to_xml(data)

        if self.verbose:
            print(result)

        root = ET.fromstring(result)
        xmlns = "http://synce.org/formats/airsync_wm5/airsync"

        status = root.find(f".//{{{xmlns}}}Status")
        if status is not None and status.text != "1":
            raise Exception(f'Sync failed with status code {status.text}')

        sync_key = root.find(f".//{{{xmlns}}}SyncKey").text
        if self.verbose:
            print("Current SyncKey:", sync_key)

        for add in root.findall(f".//{{{xmlns}}}Add"):
            serverId = add.find(f"{{{xmlns}}}ServerId").text
            print("  ServerId", serverId)
            applicationData = add.find(f"{{{xmlns}}}ApplicationData")
            if applicationData is None:
                continue

            calxmlns = "http://synce.org/formats/airsync_wm5/calendar"
            subject = applicationData.find(f"{{{calxmlns}}}Subject")
            if subject is not None:
                print("  Subject", subject.text)
            startTime = applicationData.find(f"{{{calxmlns}}}StartTime")
            if startTime is not None:
                print("  StartTime", startTime.text)
            timeZone = applicationData.find(f"{{{calxmlns}}}TimeZone")
            if timeZone is not None:
                decode_timezone(timeZone.text)
                #the dates are encoded like so: vstdyear/vstdmonth/vstdday/vstdweek/vstdhour/vstdminute/vstdsecond/vstdmillis
                decoded = base64.b64decode(timeZone.text)
                bias, standardName, standardDate, standardBias, daylightName, daylightDate, daylightBias = struct.unpack('i64s16si64s16si', decoded)
                print(f"  TimeZone bias: {bias}min")
            print("")

        end = time.time();
        print("Elapsed: " + str(end - start))
        print("\n")

        more_available = (len(root.findall(f".//{{{xmlns}}}MoreAvailable")) == 1)
        return [sync_key, more_available]


    def sync(self, collection_id, sync_key = 0, uploads = None):
        if self.sync_key is not None:
            sync_key = self.sync_key

        if uploads is not None:
            uploads = int(uploads)

        # Initial sync if required
        if sync_key == 0:
            # Required for new devices
            folders = self.list()
            # Translate name to id if applicable
            try:
                idFromName = list(folders.keys())[list(folders.values()).index(collection_id)]
                if idFromName is not None:
                    collection_id = idFromName
            except:
                pass
            [sync_key, _] = self.do_sync(collection_id, sync_key, None)
        # Fetch until there is no more to fetch
        while True:
            [sync_key, more_available] = self.do_sync(collection_id, sync_key, uploads)
            uploads = None
            # track_memory_usage()
            if not more_available:
                if self.poll:
                    time.sleep(2)
                    continue
                break


    def idFromName(self, name):
        collection_id = name
        # required for new devices
        folders = self.list()
        # Translate name to id if applicable
        try:
            idFromName = list(folders.keys())[list(folders.values()).index(collection_id)]
            if idFromName is not None:
                collection_id = idFromName
        except:
            pass

        return collection_id

    def create(self, collection_name):
        start = time.time()

        # From folder sync
        [folder_sync_key, _] = self.folder_sync()
        # mail user-created
        folder_type = 12

        request = """
        <?xml version="1.0" encoding="utf-8"?>
        <!DOCTYPE ActiveSync PUBLIC "-//MICROSOFT//DTD ActiveSync//EN" "http://www.microsoft.com/">
        <FolderCreate xmlns="FolderHierarchy:">
            <SyncKey>{folder_sync_key}</SyncKey>
            <ParentId>0</ParentId>
            <DisplayName>{collection_name}</DisplayName>
            <Type>{folder_type}</Type>
        </FolderCreate>
        """.replace('    ', '').replace('\n', '').format(collection_name=collection_name, folder_sync_key=folder_sync_key, folder_type=folder_type)

        response = self.send_request('FolderCreate', request)

        assert response.status == 200

        data = response.read()
        if not data:
            if self.verbose:
                print("Empty response, no changes on server")
            return [sync_key, False]

        result = wbxml.wbxml_to_xml(data)

        if self.verbose:
            print(result)

        root = ET.fromstring(result)
        xmlns = "http://synce.org/formats/airsync_wm5/airsync"

        status = root.find(f".//{{{xmlns}}}Status")
        if status is not None and status.text != "1":
            raise Exception(f'Create failed with status code {status.text}')

        end = time.time()
        print("Elapsed: " + str(end - start))
        print("\n")

    def ping(self, collection_id):
        start = time.time()
        collection_id = self.idFromName(collection_id)

        request = """
        <?xml version="1.0" encoding="utf-8"?>
        <!DOCTYPE AirSync PUBLIC "-//AIRSYNC//DTD AirSync//EN" "http://www.microsoft.com/">
        <Ping xmlns="uri:AirSync" xmlns:AirSyncBase="uri:AirSyncBase" xmlns:Email="uri:Email" xmlns:Tasks="uri:Tasks" >
            <HeartbeatInterval>900</HeartbeatInterval>
            <Folders>
                <Folder>
                    <Id>{collection_id}</Id>
                    <Class>Email</Class>
                </Folder>
            </Folders>
        </Ping>
        """.replace('    ', '').replace('\n', '').format(collection_id=collection_id)

        response = self.send_request('Ping', request)

        if response.status != 200:
            end = time.time()
            print("Elapsed: " + str(end - start))
            print("\n")
        assert response.status == 200

        data = response.read()
        if not data:
            if self.verbose:
                print("Empty response, no changes on server")
            return [sync_key, False]

        result = wbxml.wbxml_to_xml(data)

        if self.verbose:
            print(result)

        root = ET.fromstring(result)
        xmlns = "http://synce.org/formats/airsync_wm5/airsync"

        status = root.find(f".//{{{xmlns}}}Status")
        if status is not None and status.text != "1":
            raise Exception(f'Sync failed with status code {status.text}')

        end = time.time()
        print("Elapsed: " + str(end - start))
        print("\n")

    def folder_sync(self, sync_key = 0):
        request = """
            <?xml version="1.0" encoding="utf-8"?>
            <!DOCTYPE ActiveSync PUBLIC "-//MICROSOFT//DTD ActiveSync//EN" "http://www.microsoft.com/">
            <FolderSync xmlns="FolderHierarchy:">
                <SyncKey>{sync_key}</SyncKey>
            </FolderSync>
        """.replace('    ', '').replace('\n', '').format(sync_key=sync_key)

        response = self.send_request('FolderSync', request)

        assert response.status == 200

        wbxmldata = response.read()

        if self.verbose:
            print(wbxmldata.hex())

        result = wbxml.wbxml_to_xml(wbxmldata)

        if self.verbose:
            print(result)

        root = ET.fromstring(result)
        xmlns = "http://synce.org/formats/airsync_wm5/folderhierarchy"
        folder_sync_key = root.find(f".//{{{xmlns}}}SyncKey").text
        if self.verbose:
            print("Current SyncKey:", folder_sync_key)

        return [folder_sync_key, root]

    def list(self):
        [folder_sync_key, root] = self.folder_sync(self.folder_sync_key)

        xmlns = "http://synce.org/formats/airsync_wm5/folderhierarchy"
        folders = {}
        for add in root.findall(f".//{{{xmlns}}}Add"):
            displayName = add.find(f"{{{xmlns}}}DisplayName").text
            serverId = add.find(f"{{{xmlns}}}ServerId").text

            folders[serverId] = displayName
            print("ServerId", serverId)
            print("DisplayName", displayName)

        return folders


    def search(self, search_string):
        request = """
            <?xml version="1.0" encoding="utf-8"?>
            <!DOCTYPE ActiveSync PUBLIC "-//MICROSOFT//DTD ActiveSync//EN" "http://www.microsoft.com/">
            <Search xmlns="FolderHierarchy:">
                <Store>
                    <Name>Mailbox</Name>
                    <Query>
                        <And>
                            <Class xmlns="uri:AirSync">Email</Class>
                            <FreeText>{search_string}</FreeText>
                        </And>
                    </Query>
                    <Options>
                    <RebuildResults/>
                    <DeepTraversal/>
                    <Range>0-9</Range>
                    <BodyPreference xmlns="uri:AirSyncBase">
                        <Type>2</Type>
                        <TruncationSize>20000</TruncationSize>
                    </BodyPreference>
                    </Options>
                </Store>
            </Search>
        """.replace('    ', '').replace('\n', '').format(search_string=search_string)

        response = self.send_request('Search', request)

        assert response.status == 200

        wbxmldata = response.read()

        if self.verbose:
            print(wbxmldata.hex())

        result = wbxml.wbxml_to_xml(wbxmldata)

        if self.verbose:
            print(result)


def main():
    parser = argparse.ArgumentParser()

    parser.add_argument("--host", help="Host")
    parser.add_argument("--user", help="Username")
    parser.add_argument("--password", help="User password")
    parser.add_argument("--verbose", action='store_true', help="Verbose output")
    parser.add_argument("--profile", action='store_true', help="Send the XDEBUG_TRIGGER")
    parser.add_argument("--deviceid", help="Device identifier ")
    parser.add_argument("--devicetype", help="devicetype (WindowsOutlook15, iphone)")

    subparsers = parser.add_subparsers()

    parser_list = subparsers.add_parser('decode_timezone')
    parser_list.add_argument("timezone", help="Base64 encoded timezone string ('Lv///0lyYW....///w==') ")
    parser_list.set_defaults(func=lambda args: decode_timezone(args.timezone))

    parser_list = subparsers.add_parser('list')
    parser_list.add_argument("--folder", help="Folder")
    parser_list.add_argument("--folder_sync_key", help="Sync key to start from")
    parser_list.set_defaults(func=lambda args: ActiveSync(args).list())

    parser_list = subparsers.add_parser('sync')
    parser_list.add_argument("collectionId", help="Collection Id")
    parser_list.add_argument("--upload", help="Upload N messages", default=None)
    parser_list.add_argument("--sync_key", help="Sync key to start from")
    parser_list.add_argument("--poll", action='store_true', help="Keep syncing every 2 seconds")
    parser_list.set_defaults(func=lambda args: ActiveSync(args).sync(args.collectionId, 0, args.upload))

    parser_list = subparsers.add_parser('search')
    parser_list.add_argument("string", help="Collection Id")
    parser_list.set_defaults(func=lambda args: ActiveSync(args).search(args.string))

    parser_list = subparsers.add_parser('ping')
    parser_list.add_argument("collectionId", help="Collection Id")
    parser_list.set_defaults(func=lambda args: ActiveSync(args).ping(args.collectionId))

    parser_list = subparsers.add_parser('create')
    parser_list.add_argument("collectionName", help="Collection Name")
    parser_list.set_defaults(func=lambda args: ActiveSync(args).create(args.collectionName))

    parser_check = subparsers.add_parser('check')
    parser_check.set_defaults(func=lambda args: ActiveSync(args).check())

    options = parser.parse_args()

    if 'func' in options:
        options.func(options)


if __name__ == "__main__":
    main()
