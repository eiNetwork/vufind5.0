#cd /usr/local/vufind-5.0/;
#sudo -u vufind ./solr.sh restart > /dev/null 2>&1 &
sudo -u vufind service solr restart > /dev/null 2>&1 &
