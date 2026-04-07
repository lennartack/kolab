#!/usr/bin/env ruby

require "net/imap"
require 'thor'

class IMAP < Net::IMAP

    def send_command2(cmd, *args, &block)
      synchronize do
        tag = generate_tag
        put_string(tag + " " + cmd + CRLF)

        begin
          return get_tagged_response(tag, cmd)
        ensure
          if block
            remove_response_handler(block)
          end
        end
      end
    end

    def getmetadata(mailbox, *entries)
      synchronize do
        data = '(' + entries.join(' ') + ')'
        send_command("GETMETADATA" + " (DEPTH infinity)", mailbox, RawData.new(data))
        
        result = @responses.delete("METADATA")
        if result and result.length() > 0
          return result[-1]
        end
        return ""
      end
    end

    def setmetadata(mailbox, entry, value)
      data = '(' + entry + ' ' + '"' + IMAP.encode_utf7(value) + '"' + ')'
      send_command("SETMETADATA", mailbox, RawData.new(data))
    end

    def tag(uids, tagname)
        send_command("UID STORE", uids, RawData.new("ANNOTATION (/vendor/kolab/tag/v1/#{tagname} (value.priv \"1\"))"))
    end
end


class ImapCli < Thor
  class_option :host
  class_option :port
  class_option :username
  class_option :login_as
  class_option :password
  class_option :ssl, :type => :boolean
  class_option :debug, :type => :boolean

  no_commands {
    def imap()
      if !@imap
        if options[:ssl]
          @imap = IMAP.new(options[:host], :port => options[:port], :ssl => {
            :verify_mode => OpenSSL::SSL::VERIFY_NONE
          })
        else
          @imap = IMAP.new(options[:host], :port => options[:port], :ssl => false)
        end
        if options[:debug]
          IMAP.debug = true
        end
        if  options[:login_as]
          @imap.authenticate("PLAIN", options[:username], options[:password], authzid: options[:login_as])
        else
          @imap.authenticate("PLAIN", options[:username], options[:password])
        end
      end
      @imap
    end

    def printfolders(folders)
      folders.each do |folder|
        if not folder.attr.empty?
          puts "#{folder.name} #{folder.attr}"
        else
          puts "#{folder.name}"
        end
      end
    end
  }

  desc "login", "Login."
  def login()
    imap()
  end

  desc "list", "List."
  def list(folder = "**")
    printfolders imap.list("", folder)
  end

  desc "search", "Search."
  def search(folder, query, location="BODY")
    p imap.select(folder)
    imap.search([location, query])
  end

  desc "lsub", "List subscriptions."
  def lsub(folder = "**")
    printfolders imap.lsub("", folder)
  end

  desc "namespace", "Namespace."
  def namespace()
    p imap.namespace()
  end

  desc "capability", "Capability."
  def capability()
    p imap.capability()
  end

  desc "select", "Select."
  def select(folder)
    p imap.select(folder)
  end

  desc "status", "Status."
  def status(folder)
    p imap.status(folder, ["MESSAGES", "RECENT", "UNSEEN"])
  end

  desc "create", "Create."
  def create(folder)
    p imap.create(folder)
  end

  desc "delete", "Delete."
  def delete(folder, entry="")
    if entry != ""
      imap.select(folder)
      p imap.store(entry, "+FLAGS", [:Deleted])
    else
      p "deleting folder"
      # p imap.delete(folder)
    end
  end

  desc "subscribe", "Subscribe."
  def subscribe(folder)
    p imap.subscribe(folder)
  end

  desc "unsubscribe", "Unsubscribe."
  def unsubscribe(folder)
    p imap.unsubscribe(folder)
  end

  desc "getmetadata", "Getmetadata."
  def getmetadata(folder, *entries)
    # p imap.select(folder)
    p imap.getmetadata(folder, entries)
  end

  desc "idle", "IDLE."
  def idle(folder, *entries)
    p imap.select(folder)
    imap.send_command2("IDLE")
  end

  desc "getacl", "getacl."
  def getacl(folder)
    p imap.getacl(folder)
  end

  desc "setacl", "setacl."
  def setacl(folder, user, rights)
    p imap.setacl(folder, user, rights)
  end

  desc "setmetadata", "Setmetadata."
  def setmetadata(folder, entry, value)
    p imap.setmetadata(folder, entry, value)
  end

  desc "tag", "Tag."
  def tag(folder, entry, value)
    imap.select(folder)
    p imap.tag(entry, value)
  end

  desc "fetch", "Fetch."
  method_option :tags, :type => :boolean, :default => false
  def fetch(folder, entry)
    imap.select(folder)
    # The eval is to translate a string to a range; e.g. 1..2
    # fields = "BODY[HEADER.FIELDS (SUBJECT FLAGS FROM TO CC REPLYTO MESSAGEID DATE SIZE REFERENCES)]"
    fields = "BODY[HEADER.FIELDS (SUBJECT DATE)]"
    if options[:tags]
      # ANNOTATION currently leads to a parsing crash because of incomplete support in
      # /usr/local/share/gems/gems/net-imap-0.5.4/lib/net/imap/response_parser/parser_utils.rb:239,
      # still useful with --debug though.
      imap.uid_fetch(eval(entry), ["UID", fields, "ANNOTATION (/vendor/kolab/tag/v1/% value.priv)"]).each do |mail|
        uid = mail.attr["UID"]
        headers = mail.attr[fields]
        p "#{uid} #{headers}"
      end
    else
      imap.uid_fetch(eval(entry), ["UID", "FLAGS", fields]).each do |mail|
        uid = mail.attr["UID"]
        headers = mail.attr[fields]
        flags = mail.attr["FLAGS"]
        p "#{uid} #{flags} #{headers}"
      end
    end
  end

  desc "undelete", "Undelete."
  def undelete(folder, entry)
    imap.select(folder)
    imap.uid_store(entry, "-FLAGS", [:Deleted])
  end

  desc "append", "APPEND."
  def append(folder, filepath)
    file = File.open(filepath)
    file_data = file.read
    p imap.append(folder, file_data)
  end

  desc "download", "Download."
  def download(folder, destination)
    imap.select(folder)
    #Dir.mkdir destination unless File.exists? destination
    Dir.mkdir destination
    imap.uid_fetch(1..-1, "RFC822").each do |mail|
      uid = mail.attr["UID"]
      p uid
      File.write("#{destination}#{uid}.", mail.attr["RFC822"])
    end
  end

end

begin
    ImapCli.start(ARGV)
rescue => e
    puts e.message
    puts e.backtrace.inspect
    raise e
end
