[![GitHub release](https://img.shields.io/github/release/CodistoConnect/CodistoConnect.svg?style=plastic)](https://github.com/CodistoConnect/CodistoConnect/releases)
[ ![License] [license-image] ] [license]

![CodistoConnect eBay better logo](https://s3-ap-southeast-2.amazonaws.com/codisto/CodistoHeaderLogo.jpg)

<h2>Welcome</h2>
<p>
Welcome to Codisto Connect Magento to eBay Integration!
</p>

<p>
Codisto Connect is revolutionary eBay integration for Magento. Incredibly fast set up and super easy maintenance. Integrate in minutes.
</p>



|Contents      |
|------------- |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#new-to-codisto-connect-need-some-help">Help</a>  |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#assistance-with-magento-">Magento Assistance</a>  |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#step-1-verify-your-prerequisites">Pre-requisites</a>  |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#step-2-prepare-to-install">Installation</a>  |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#contributing-to-the-codisto-connect-code-base">Contributing</a>  |
|<a href="https://github.com/CodistoConnect/CodistoConnect/tree/bm_readme_update#copyright-and-license">License</a>  |


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
			<td>PHP, Apache and MySQL versions supported are those specified by the supported Magento versions. (Whilst Magento 1.X requires PHP 5.4 CodistoConnect aims to be compatible with PHP 5.2) </td>
			<td><a href="http://magento.com/resources/system-requirements">Magento system requirements</a></td>
			<td><a href="http://help.codisto.com/article/25-verify-your-prerequisites">See our help article</a></td>
		</tr>
		<tr>
			<td>sqlite PDO driver</td>
			<td><a href="http://php.net/manual/en/function.phpinfo.php">Check enabled PDO drivers using phpinfo</a></td>
			<td><a href="http://php.net/manual/en/pdo.drivers.php">Read more about PDO drivers</a></td>
        </tr>
</tbody>
</table>

<h2>Step 2: Prepare to install</h2>

After verifying your prerequisites, perform the following task in order to prepare to install the Codisto Connect plugin.

<hr>

<h5>Download the Codisto Connect plugin from the site</h5>

[ ![Stable] [stable-image] ] [stable] <a href="https://codisto.com/plugin/getstable">https://qa.codisto.com/plugin/getstable</a> <br>
[ ![Beta] [beta-image] ] [beta] <a href="https://codisto.com/plugin/getstable">https://qa.codisto.com/plugin/getbeta</a> <br>
[ ![Feature] [feature-image] [feature] qa.codisto.com/plugin/build/manual?branch=$FEATUREBRANCH&download=1


OR

<h5>Download from github</h5>

Download the plugin from https://github.com/codistoconnect/codistoconnect/releases. <br>
The latest release is available from https://github.com/codistoconnect/codistoconnect/releases/latest. <br>
The plugin.tgz file located here is ready to install in Magento.



<h2>Step 3: Install and verify the installation</h2>

<h5>Follow the guide here <a href="https://codisto.com/install.html">here</a></h5>

OR

<h5>via SSH (Replace paths as appropriate)</h5>

``` bash
cd $PATHTOYOURMAGENTO
./mage uninstall community CodistoConnect
wget -O plugin.tgz https://codisto.com/plugin/getstable
./mage install-file plugin.tgz
rm plugin.tgz
```

OR

<h5>via SSH (using the CodistoConnect plugin install helper script)</h5>

``` bash
ssh $USER@$DOMAIN "wget -O install.sh https://qa.codisto.com/plugin/install && chmod +x ./install.sh && ./install.sh"
```

You may also pipe to your shell of choice but that is discouraged as things can go wrong
``` bash
ssh $USER@DOMAIN "wget -O - https://qa.codisto.com/plugin/install | $SHELL"
```


<h2>Contributing to the Codisto Connect code base</h2>
Contributions can take the form of new components or features, changes to existing features, tests, documentation (such as developer guides, user guides, examples, or specifications), bug fixes, optimizations, or just good suggestions.

Please contact <a href="https://codisto.com/contact-us.html">support</a> with your ideas and feel free to contribute

<h2>Copyright and license</h2>
Codisto Connect code set is licensed under the Open Software License 3.0 (OSL-3.0)
<a href="[license]">http://opensource.org/licenses/OSL-3.0</a> You may not claim intellectual property or exclusive ownership rights to Codisto Connect. Codisto Connect is the property of On Technology.

**Codisto Connect - Magento to eBay Integration**
[Twitter](https://twitter.com/Codisto/) | [Facebook](https://www.facebook.com/Codisto) | [Google](https://plus.google.com/+CodistoConnect/)

[feature]: Feature Branch
[feature-image]: https://img.shields.io/badge/-Feature-yellow.svg

[stable]: Stable
[stable-image]: https://img.shields.io/badge/-Stable-brightgreen.svg

[beta]: Beta
[beta-image]: https://img.shields.io/badge/-Beta-orange.svg

[license-image]: https://img.shields.io/badge/license-OSL--3.0-blue.svg
[license]: http://opensource.org/licenses/OSL-3.0
