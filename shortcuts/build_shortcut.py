#!/usr/bin/env python3
"""Builds shortcuts/Health-Check-Invio.unsigned.shortcut (plist) and signs it with the macOS `shortcuts sign` tool.

The shortcut collects the health samples of the last 2 days per type with "Find Health Samples", reads the details
(value, unit, start, end, source) as lists, joins each list with new lines and POSTs one flat JSON object
("<type>_values", "<type>_starts", ...) to /apps/healthcheck/api/ingest with the header X-HC-Token.
No loop over single samples (that would take minutes for ~700 heart-rate samples).
Server URL and token are Import Questions, asked once when the shortcut is added on the iPhone.
Run on a Mac: python3 shortcuts/build_shortcut.py"""
import plistlib
import subprocess
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
OBJ = "￼"

# our key -> Health type as named in the Shortcuts filter (English identifiers stored in the file)
TYPES = [
    ("sleep", "Sleep"), ("heart_rate", "Heart Rate"), ("resting_hr", "Resting Heart Rate"), ("spo2", "Blood Oxygen"),
    ("hrv", "Heart Rate Variability"), ("steps", "Steps"), ("distance", "Walking + Running Distance"), ("cycling", "Cycling Distance"),
    ("active_energy", "Active Calories"), ("flights", "Flights Climbed"), ("weight", "Weight"), ("body_fat", "Body Fat Percentage"),
    ("vo2max", "Cardio Fitness"),
]
DETAILS = [("values", "Value"), ("units", "Unit"), ("starts", "Start Date"), ("ends", "End Date"), ("sources", "Source")]


def uid():
    return str(uuid.uuid4()).upper()


def out(u, name):
    return {"OutputUUID": u, "Type": "ActionOutput", "OutputName": name}


def token(u, name):
    """a whole parameter that is just the output of an earlier action"""
    return {"Value": out(u, name), "WFSerializationType": "WFTextTokenAttachment"}


def tstring(text, attach=None):
    """text with an optional variable at the place of OBJ"""
    v = {"string": text, "attachmentsByRange": {}}
    if attach is not None:
        v["attachmentsByRange"] = {"{%d, 1}" % text.index(OBJ): out(*attach)}
    return {"Value": v, "WFSerializationType": "WFTextTokenString"}


def dict_field(items):
    return {"Value": {"WFDictionaryFieldValueItems": [{"WFItemType": 0, "WFKey": tstring(k), "WFValue": v} for k, v in items]},
            "WFSerializationType": "WFDictionaryFieldValue"}


actions = []


def add(identifier, params, u=None):
    u = u or uid()
    p = {"UUID": u}
    p.update(params)
    actions.append({"WFWorkflowActionIdentifier": identifier, "WFWorkflowActionParameters": p})
    return u


# 0/1: server URL and token (filled by Import Questions)
u_url = add("is.workflow.actions.gettext", {"WFTextActionText": "", "CustomOutputName": "Health Check URL"})
u_tok = add("is.workflow.actions.gettext", {"WFTextActionText": "", "CustomOutputName": "Health Check Token"})
json_items = [("v", tstring("1")), ("client", tstring("shortcut-1"))]
for key, hk in TYPES:
    u_find = add("is.workflow.actions.filter.health.quantity", {
        "WFContentItemFilter": {"Value": {"WFActionParameterFilterPrefix": 1, "WFContentPredicateBoundedDate": False, "WFActionParameterFilterTemplates": [
            {"Bounded": True, "Property": "Type", "Operator": 4, "Removable": False,
             "Values": {"Enumeration": {"Value": hk, "WFSerializationType": "WFStringSubstitutableState"}}},
            {"Bounded": True, "Property": "Start Date", "Operator": 1001, "Removable": True, "Values": {"Number": "2", "Unit": 16}},
        ]}, "WFSerializationType": "WFContentPredicateTableTemplate"},
        "WFContentItemSortProperty": "Start Date", "WFContentItemSortOrder": "Oldest First", "WFContentItemLimitEnabled": False,
        "CustomOutputName": "Samples " + key,
    })
    for field, prop in DETAILS:
        u_det = add("is.workflow.actions.properties.health.quantity", {"WFInput": token(u_find, "Samples " + key), "WFContentItemPropertyName": prop})
        src = (u_det, prop)
        if prop in ("Start Date", "End Date"):
            u_fmt = add("is.workflow.actions.format.date", {"WFDate": tstring(OBJ, src), "WFDateFormatStyle": "ISO 8601", "WFISO8601IncludeTime": True})
            src = (u_fmt, "Formatted Date")
        u_join = add("is.workflow.actions.text.combine", {"text": token(*src), "WFTextSeparator": "New Lines", "CustomOutputName": key + "_" + field})
        json_items.append((key + "_" + field, tstring(OBJ, (u_join, key + "_" + field))))

add("is.workflow.actions.downloadurl", {
    "WFURL": tstring(OBJ, (u_url, "Health Check URL")),
    "WFHTTPMethod": "POST",
    "WFHTTPBodyType": "JSON",
    "ShowHeaders": True,
    "WFHTTPHeaders": dict_field([("X-HC-Token", tstring(OBJ, (u_tok, "Health Check Token")))]),
    "WFJSONValues": dict_field(json_items),
})

wf = {
    "WFWorkflowClientVersion": "2607.0.2",
    "WFWorkflowMinimumClientVersion": 900,
    "WFWorkflowMinimumClientVersionString": "900",
    "WFWorkflowIcon": {"WFWorkflowIconStartColor": 4282601983, "WFWorkflowIconGlyphNumber": 59511},
    "WFWorkflowImportQuestions": [
        {"ActionIndex": 0, "Category": "Parameter", "ParameterKey": "WFTextActionText", "DefaultValue": "https://",
         "Text": "Indirizzo di invio (da Health Check → Dati → Invio automatico), es. https://cloud.example.org/apps/healthcheck/api/ingest"},
        {"ActionIndex": 1, "Category": "Parameter", "ParameterKey": "WFTextActionText", "DefaultValue": "",
         "Text": "Token personale (Health Check → Dati → Invio automatico → «Crea token»)"},
    ],
    "WFWorkflowTypes": [],
    "WFWorkflowInputContentItemClasses": [],
    "WFWorkflowHasShortcutInputVariables": False,
    "WFWorkflowHasOutputFallback": False,
    "WFWorkflowOutputContentItemClasses": [],
    "WFQuickActionSurfaces": [],
    "WFWorkflowActions": actions,
}
unsigned = HERE / "Health-Check-Invio.unsigned.shortcut"
signed = HERE / "Health-Check-Invio.shortcut"
with open(unsigned, "wb") as f:
    plistlib.dump(wf, f, fmt=plistlib.FMT_BINARY)
print(len(actions), "actions ->", unsigned)
r = subprocess.run(["shortcuts", "sign", "--mode", "anyone", "--input", str(unsigned), "--output", str(signed)], capture_output=True, text=True)
print("sign:", r.returncode, r.stdout.strip(), r.stderr.strip()[:500])
if r.returncode == 0:
    unsigned.unlink()
    print(signed, signed.stat().st_size, "bytes")
