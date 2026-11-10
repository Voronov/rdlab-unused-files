#
# Files the extension moved to a recycler folder, kept to restore them to their original place
#
CREATE TABLE tx_rdlabunusedfiles_recycled (
	uid int(11) unsigned NOT NULL auto_increment,
	storage int(11) unsigned DEFAULT '0' NOT NULL,
	original_identifier text,
	recycler_identifier text,
	name varchar(255) DEFAULT '' NOT NULL,
	size bigint(20) unsigned DEFAULT '0' NOT NULL,
	deleted_at int(11) unsigned DEFAULT '0' NOT NULL,
	deleted_by varchar(255) DEFAULT '' NOT NULL,

	PRIMARY KEY (uid),
	KEY deleted_at (deleted_at)
);
