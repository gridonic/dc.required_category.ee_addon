#!/bin/bash

# This script creates symlinks from the local GIT repo into your EE install. It also copies some of the extension icons.
# Original idea spinned off Leevi Graham, http://github.com/newism.  Thanks Leevi.

script_path=$(cd $(dirname $0); pwd)

echo "
You are about to create symlinks for DC Required Category
---------------------------------------------------------

The symlinks use absolute paths so they are for development purposes only.

The following directories must be writable:

    system/extensions
    system/modules
    system/language
    system/lib
    themes/cp_global_images
    themes/cp_themes/default
    themes/site_themes

Enter the full path to your ExpressionEngine install *without a trailing slash* [ENTER]:"
read ee_path
echo "
Enter your ee system folder name [ENTER]:"
read ee_system_folder

# extension
ln -sfv "$script_path"/extensions/ext.dc_required_category.php "$ee_path"/"$ee_system_folder"/extensions/ext.dc_required_category.php

# language
ln -sfv "$script_path"/language/english/lang.dc_required_category.php "$ee_path"/"$ee_system_folder"/language/english/lang.dc_required_category.php