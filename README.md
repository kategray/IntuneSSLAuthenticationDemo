## Intune device authentication demo

This software demonstrates how an application can require devices to be associated with InTune in order to access 
resources.  By looking the client up by ID, it is possible to determine the MDM status, user, compliance, MAC address, 
etc.

![Screen Shot](https://raw.githubusercontent.com/kategray/IntuneSSLAuthenticationDemo/master/doc/screen_shot.png)

When a device is managed by Intune, it is provided a SSL Certificate from the Microsoft Intune Device CA.  We can use 
this certificate to identify the device and ensure that it's in compliance.

You will need to register your application in the Azure directory to grant it permissions, then modify .env to add the
credentials to the application.

The application must have the DeviceManagementManagedDevices.ReadWrite.All permission.

Your httpd.conf should look something like this:
```
<IfModule mod_ssl.c>
	<VirtualHost _default_:443>
		DocumentRoot /var/www/protected/htdocs

		SSLEngine on
		SSLCACertificateFile /etc/ssl/certs/intune.pem
		SSLVerifyClient require
		SSLVerifyDepth	10

		<FilesMatch "\.(cgi|shtml|phtml|php)$">
				SSLOptions +StdEnvVars
		</FilesMatch>
</VirtualHost>
</IfModule>
```