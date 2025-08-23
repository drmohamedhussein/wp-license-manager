import re

def main():
    # Read cli.php content
    with open('wp-license-manager/includes/cli.php', 'r') as f:
        cli_content = f.read()

    # Read class-admin-manager.php content
    with open('wp-license-manager/includes/class-admin-manager.php', 'r') as f:
        admin_manager_content = f.read()

    # Extract the function from cli_content
    # Using regex to be more robust to variations in whitespace/comments
    cli_function_pattern = r"function wplm_generate_standard_license_key\\(\\): string \\{[\\s\\S]*?return implode\\(-\', \\$key_parts\\);\\n\\}"
    match = re.search(cli_function_pattern, cli_content)
    
    if not match:
        print("Error: Could not find 'wplm_generate_standard_license_key' function in cli.php")
        return

    license_key_function = match.group(0)

    # Modify the extracted function for insertion into the class
    # Rename function and add private static visibility
    license_key_function_modified = license_key_function.replace(
        "function wplm_generate_standard_license_key(): string {",
        "    private static function generate_unique_license_key(): string {"
    )
    # Indent the body of the function further
    # We need to re-indent the entire function content, not just prepend
    # Remove existing indentation first, then add class-level indentation
    lines = license_key_function_modified.splitlines()
    re_indented_lines = []
    for line in lines:
        stripped_line = line.lstrip()
        if stripped_line: # Only indent if line is not empty
            re_indented_lines.append("        " + stripped_line) # 8 spaces for method inside class
        else:
            re_indented_lines.append("") # Keep empty lines
    license_key_function_modified = "\\n".join(re_indented_lines)


    # Find the insertion point in admin_manager_content
    # Look for the last closing brace of the class
    # Add a unique marker to ensure correct placement, as rfind('}') might be ambiguous
    class_end_marker = "}" # This will be the very last '}' of the class.

    # Find the index of the last '}'
    class_end_brace_index = admin_manager_content.rfind(class_end_marker)

    if class_end_brace_index == -1:
        print("Error: Could not find class ending brace in class-admin-manager.php")
        return

    # Insert the new function before the closing brace
    new_admin_manager_content = (\
        admin_manager_content[:class_end_brace_index]
        + "\\n" * 2  # Add some blank lines for readability
        + license_key_function_modified
        + "\\n" * 2
        + admin_manager_content[class_end_brace_index:]
    )

    # Update the call in ajax_generate_key
    # This regex ensures we only replace the specific call
    new_admin_manager_content = re.sub(
        r"WPLM_Automated_Licenser::generate_unique_license_key\\(\\);",
        r"self::generate_unique_license_key();",
        new_admin_manager_content
    )

    # Write the new content to a temporary file
    with open('class-admin-manager.php.tmp', 'w') as f:
        f.write(new_admin_manager_content)

    print("Generated new class-admin-manager.php.tmp")

if __name__ == '__main__':
    main()
