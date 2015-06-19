[![GitHub release](http://img.shields.io/badge/release-1.04-blue.svg?style=plastic)](https://github.com/CodistoConnect/CodistoConnect/releases)
[ ![License] [license-image] ] [license]

![CodistoConnect eBay better logo](https://s3-ap-southeast-2.amazonaws.com/codisto/CodistoHeaderLogo.jpg)

<h2>Welcome</h2>
<p>
Welcome to Codisto Connect Magento to eBay Integration!
</p>

<p>
Codisto Connect is revolutionary eBay integration for Magento. Incredibly fast set up and super easy maintenance. Integrate in minutes.
</p>

<h2>New to Codisto Connect? Need some help?</h2>
If you're not sure about the following, you probably need a little help before you start installing the Codisto Connect plugin:

* What is Codisto Connect ? How can I find out some more information ? Please take a look on the website <a href="https://codisto.com/">here</a> for a helpful video, contact information and a live demonstration.
* How do I get assistance with Codisto Connect ? Articles are available <a href="https://codisto.com/help.html">here</a> or support is reachable <a href="https://codisto.com/contact-us.html">here</a>.


<h2>Assistance with Magento ?</h2>
Magento documentation is outside of the scope of Codisto Connect. Assistance is provided for some common Magento related queries below.

*	Is the Magento software <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_magento-installed.html">installed already</a>?
*	What's a <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_login.html">terminal, command prompt, or Secure Shell (ssh)</a>?
*	Where's my <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_login.html">Magento server</a> and how do I access it?
*	What's <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_software.html">PHP</a>?
*	What's <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_software.html">Apache</a>?
*	What's <a href="http://devdocs.magento.com/guides/v1.0/install-gde/basics/basics_software.html">MySQL</a>?


<h2>Step 1: Verify your prerequisites</h2>

Use the following table to verify you have the correct prerequisites to install Codisto Connect.

<table>
	<tbody>
		<tr>
			<th>Prerequisite</th>
			<th>How to check</th>
			<th>For more information</th>
		</tr>
		<tr>
			<td>Magento Community Edition (versions 1.6.0.0 - 1.9.1.1) <br>
			Magento Enterprise Edition (versions 1.11 - 1.14)</td>
			<td>To check your version consult Magento documentation or use a site such as <a href="http://magentoversion.com/">http://magentoversion.com/</a></td>
			<td><a href="http://magento.com/products/overview">Magento products overview</a></td>
		</tr>
		<tr>
			<td>PHP, Apache and MySQL versions supported are those specified by the supported Magento versions.</td>
			<td>Consult the table on Magento's github.com repository <a href="https://github.com/magento/magento2/blob/develop/README.md">here</a></td>
			<td><a href="http://magento.com/resources/system-requirements">Magento system requirements</td>
		</tr>
</tbody>
</table>

<h2>Step 2: Prepare to install</h2>

After verifying your prerequisites, perform the following tasks in order to prepare to install the Codisto Connect plugin.

1.	Download the Codisto Connect plugin from Codisto.com at <a href="https://codisto.com/plugin/getstable">https://codisto.com/plugin/getstable</a>. Substitute getstable with getbeta
for the latest development branch. (Other feature branches will have to be packaged manually - <a href="http://www.magentocommerce.com/magento-connect/create_your_extension/">Create your extension></a>)

<h2>Step 3: Install and verify the installation</h2>

1.	Follow the guide here <a href="https://codisto.zendesk.com/hc/en-us/articles/204370649-How-to-list">Installing Codisto Connect</a>.

OR

Open the Admin area on your Magento site and click System -> Magento Connect -> Magento Connect Manager. In the area "Direct package file upload" select "Choose File" (select the file from Step 2 point 1, followed by Upload

OR via SSH (Replace paths as appropriate)

``` bash
cd ~/apps/magento/htdocs/
./mage uninstall community CodistoConnect
wget -O plugin.tgz https://codisto.com/plugin/getstable
./mage install-file plugin.tgz
rm plugin.tgz
```

<h2>Contributing to the Codisto Connect code base</h2>
Contributions can take the form of new components or features, changes to existing features, tests, documentation (such as developer guides, user guides, examples, or specifications), bug fixes, optimizations, or just good suggestions.

Please contact <a href="https://codisto.com/contact-us.html">support</a> with your ideas and feel free to contribute

<h2>Copyright and license</h2>
Codisto Connect code set is licensed under the Open Software License 3.0 (OSL-3.0)
<a href="[license]">http://opensource.org/licenses/OSL-3.0</a> You may not claim intellectual property or exclusive ownership rights to Codisto Connect. Codisto Connect is the property of On Technology.

**Codisto Connect - Magento to eBay Integration**
[Twitter](https://twitter.com/Codisto/) | [Facebook](https://www.facebook.com/Codisto) | [Google](https://plus.google.com/+CodistoConnect/)


[license-image]: https://img.shields.io/badge/license-OSL--3.0-blue.svg
[license]: http://opensource.org/licenses/OSL-3.0
