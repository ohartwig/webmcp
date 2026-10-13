CREATE TABLE tx_webmcp_tool_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tool_name varchar(100) DEFAULT '' NOT NULL,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    root_page_id int(11) DEFAULT '0' NOT NULL,
    user_agent varchar(500) DEFAULT '' NOT NULL,
    ip_hash varchar(64) DEFAULT '' NOT NULL,
    success tinyint(1) unsigned DEFAULT '0' NOT NULL,
    duration_ms int(11) unsigned DEFAULT '0' NOT NULL,
    error_message text,
    PRIMARY KEY (uid),
    KEY idx_site (site_identifier),
    KEY idx_tool (tool_name),
    KEY idx_crdate (crdate)
);
