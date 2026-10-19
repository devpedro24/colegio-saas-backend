If WScript.Arguments.Count <> 3 Then
    WScript.Quit 1
End If

Set shell = CreateObject("WScript.Shell")
command = """" & WScript.Arguments(0) & """ -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & WScript.Arguments(1) & """ -Action Run -PhpPath """ & WScript.Arguments(2) & """"
WScript.Quit shell.Run(command, 0, True)
