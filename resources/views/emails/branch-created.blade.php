<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Branch Created</title>
</head>

<body style="
    margin: 0;
    padding: 0;
    background: #f4f7fb;
    font-family: Arial, Helvetica, sans-serif;
">
<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    style="padding: 30px 12px;"
>
    <tr>
        <td align="center">
            <table
                width="100%"
                cellpadding="0"
                cellspacing="0"
                style="
                    max-width: 620px;
                    background: #ffffff;
                    border: 1px solid #e5e7eb;
                    border-radius: 16px;
                    overflow: hidden;
                "
            >
                <tr>
                    <td style="
                        padding: 28px 32px;
                        background: #2563eb;
                        color: #ffffff;
                    ">
                        <h1 style="margin: 0; font-size: 24px;">
                            Branch created successfully
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px;">
                        <p>
                            Hello {{ $manager->name }},
                        </p>

                        <p style="
                            color: #475569;
                            line-height: 1.7;
                        ">
                            Your Tukaatu Express branch has been
                            created successfully.
                        </p>

                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            style="
                                background: #f8fafc;
                                border: 1px solid #e2e8f0;
                                border-radius: 12px;
                                margin: 22px 0;
                            "
                        >
                            <tr>
                                <td style="padding: 18px;">
                                    <strong>Branch:</strong>
                                    {{ $branch->name }}
                                    <br><br>

                                    <strong>Branch code:</strong>
                                    {{ $branch->code }}
                                    <br><br>

                                    <strong>Manager username:</strong>
                                    {{ $manager->username }}
                                    <br><br>

                                    <strong>Prepared accounts:</strong>
                                    {{ $generatedUserCount }}
                                </td>
                            </tr>
                        </table>

                        <p style="
                            color: #475569;
                            line-height: 1.7;
                        ">
                            Create your password and log in to review
                            the prepared branch positions and their
                            temporary credentials.
                        </p>

                        <p style="margin: 28px 0;">
                            <a
                                href="{{ $setPasswordUrl }}"
                                style="
                                    display: inline-block;
                                    padding: 14px 22px;
                                    background: #2563eb;
                                    color: #ffffff;
                                    text-decoration: none;
                                    border-radius: 9px;
                                    font-weight: 700;
                                "
                            >
                                Set Password and Access Branch
                            </a>
                        </p>

                        <p style="
                            color: #64748b;
                            font-size: 13px;
                        ">
                            Login URL:
                            <a href="{{ $loginUrl }}">
                                {{ $loginUrl }}
                            </a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>