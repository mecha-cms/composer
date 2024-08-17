Installer for [Mecha](https://github.com/mecha-cms/mecha)
=========================================================

Custom installer using [Composer](https://getcomposer.org/doc/articles/custom-installers.md).

Usage
-----

~~~ sh
composer create-project mecha-cms/mecha
cd mecha
composer require mecha-cms/x.panel
~~~

> [!IMPORTANT]
>
> This plugin will delete the files and folders listed in the `extra.remove-on-install` property of the root
> `composer.json` file. The existence of a `.gitattributes` file on a GitHub project may cause some confusion, as
> removing a specific list of files or folders from the `extra.remove-on-install` property will not prevent those files
> and folders from being deleted by the plugin.
>
> This is because the `export-ignore` command in a `.gitattributes` file is more dominant than the plugin’s file and
> folder delete commands, so if there are `export-ignore` commands in the GitHub project’s `.gitattributes` file, then
> those files and folders will be automatically excluded from the Composer package.
>
> There is nothing you can do in this case except to ask the developer of that extension or layout not to mark certain
> files and folders as being subject to removal from the package.